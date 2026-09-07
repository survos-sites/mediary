# Mediary failed queue — taxonomy before purge (2026-09-06)

Snapshot of all **46,141** messages in the `failed` transport, captured before clearing the
queues. The queue was the only record of these, so this file replaces it.

Counts came from `messenger_messages` directly: `messenger:failed:show --stats` OOMs at this
size (it calls `DoctrineReceiver->all(null)`, loading all 46k rows — 805 MB limit exhausted in
`LineFormatter`). Inspect individual messages with `messenger:failed:show <id> -vv`, which is fine.

## Queue depths at snapshot

**The transport is hybrid, and the two columns below are two different brokers.** `asset.*`
(state-bundle dynamic per-transition queues, `survos_state.queue_driver: rabbitmq`) and `webhook`
are on **RabbitMQ**; `meili`, `cache_warm` and `failed` are on **Doctrine**. `failed` is pinned to
Doctrine deliberately — see the comment in `config/packages/messenger.yaml`: under AMQP a failed
message is invisible to both `messenger:failed:*` and zenstruck/messenger-monitor. That decision is
the only reason this taxonomy could be produced at all.

| queue | rows in `messenger_messages` | `messenger:stats` | note |
|---|---|---|---|
| `cache_warm` | 66,204 | 66,204 | Doctrine |
| `failed` | 46,141 | 46,141 | Doctrine |
| `media.record.queue.split` | 340 | 78 | **AMQP live; the 340 are Doctrine orphans** |
| `asset.info` | 292 | 0 | **AMQP live (empty); the 292 are Doctrine orphans** |
| `meili` | 217 | 205 | Doctrine |

Where the two columns disagree, `messenger:stats` is reading RabbitMQ while the table rows are
leftovers from before those queues moved to AMQP. **Nothing consumes those 632 rows.** So
`TRUNCATE messenger_messages` is the cleaner reset, not the riskier one — it clears the Doctrine
side including the orphans, and cannot touch the live AMQP queues (purge those on `:15672`).

`asset.*` all read 0 while `info: 2` and `archive: 2` workers run idle — the workers are healthy;
nothing is being enqueued for them.

## The 46,141, by originating transport

| transport | count | cause |
|---|---|---|
| `asset.archive` | 42,343 | source fetch failed (breakdown below) |
| `asset.info` | 3,372 | imgproxy `/info` rejected the archived object |
| `meili` | 350 | indexing |
| `webhook` | 76 | `asset.analyzed` callback rejected |

### `asset.archive` — 42,343

| source status | count | note |
|---|---|---|
| **413** | **32,426** | **Fortepan via AWS.** All sampled are `djhqbtjenf12x.cloudfront.net/<base64>`, an AWS Serverless Image Handler. The payload decodes to `{"bucket":"fortepanproject","key":"288654.jpg","edits":{"resize":[]}}` — an **empty `resize`**, i.e. "return the full-size original", which 413s on anything large. Solvable: request a real resize (e.g. `{"resize":{"width":3000}}`) so the response stays under the handler's limit. Fortepan pipes everything through AWS; this has been seen before. |
| 500 | 8,929 | upstream source errors |
| 502 | 748 | upstream gateway |
| 429 | 192 | rate limited — retryable |
| 504 | 34 | upstream timeout |
| 404 | 14 | ssai scans via the Mac depot, e.g. `mac-depot-img.scanstationai.work/unsafe/preset:thumb/plain/local:///scans/tac-shack-0011/page-005.png@webp`. Depot not serving. Raised as `UnrecoverableMessageHandlingException` at `AssetWorkflow.php:585`. |

### `asset.info` — 3,372

3,372 failed rows over **1,690 distinct URLs** (~2 attempts each). Sources are
`s3://museado/orig/...`, the correct scheme after upload.

**Most of these were transient and already succeed on retry.** Re-issuing a failed `/info` call
for a `.jpg` source now returns **HTTP 200** with a complete response (blurhash, average, dominant
colors). So the bulk is not undecodable content — imgproxy or its route to S3 was simply
unavailable when they ran. **These are safe to re-submit and should be.**

The genuine exception is video. A `.webm` source still returns **422 `Failed to detect source
image type`** on retry today, and always will. Non-image content is **tiny**: across all
**653,017** assets there are only **4 `.webm`**, **7 `.pdf`**, and zero `.mp4`/`.mov`/`.mp3`.

So the split is: ~4 permanent (video), the rest transient.

Worth fixing anyway: `ImageUrl::NON_IMAGE_EXTENSIONS` (survos/data-contracts) lists only
`xml, html, htm, json, txt, csv, zip`, so a `.webm` classifies as `Unverifiable` → `isRenderable()`
true → it passes the ingest gate and gets pushed. Video/audio extensions should be filtered before
dispatch. Model it like `Document` (a real asset that imgproxy cannot rasterise), not like
`NotAnImage` (a wrong field picked at harvest) — `isHarvestDefect()` should stay false for it, and
that leaves room for video tools later.

### `webhook` — 76

`asset.analyzed` callbacks to `https://laptop-harvest.scanstationai.work/webhook/mediary`
rejected with **502**, dated 2026-08-19 — the laptop tunnel was down. Stale; safe to discard.

## Separately: 452,135 assets sit at `archived`

`PLACE_ARCHIVED` declares `next: [info]`, but only ~3.4k `info` transitions were ever attempted
(and all failed). The rest were never dispatched at all. This is a standing backlog, not a
per-dataset problem — `state:iterate Asset -m archived -t info` unfiltered would enqueue 452,135
messages, so always scope it with `--filter` and `--limit`.

Also note `BatchController.php:123` only dispatches when `marking === PLACE_NEW`, so a client
re-POSTing a stalled asset gets `"dispatched": "no"` and has no way to restart it. An asset stalled
at a non-terminal, non-`new` place currently has **no client-side recovery path**.

## Console errors on mediary are masked

`bin/console` failures surface as `The "ntfy" scheme is not supported`
(`Notifier/Transport.php:169`) — the notifier throws while reporting the real error. Hit while
running `dbal:run-sql`; the actual error was a quoting mistake, completely hidden behind the ntfy
exception. Fix this early, it makes everything else harder to diagnose.
