# mediary — the Survos media server

mediary owns media binaries: it downloads a URL once, archives the original to S3, derives sized
variants through imgproxy, runs whatever AI a client asked for, and reports back. Clients hold
metadata rows; mediary holds the files.

The point is that nothing blocks on a thumbnail. A client asks for a URL, gets an id immediately,
and hears back when the work is done — rather than freezing a page render on an image that does
not exist yet.

Storage is [flysystem](https://github.com/thephpleague/flysystem-bundle), so the archive backend
is configurable. Each client registers as a `User` with a code, which is both its API key and its
root path in storage.

---

## One table, one owner

mediary has an **`Asset`** table and no `Media` table. It used to have both, which was confusing
for everyone: two rows describing one image, each free to drift from the other.

The split that survives is the one that means something:

- **`Asset`** (here) — the file. Storage key, archive URL, dimensions, mime, workflow marking.
- **`Media`** (in the *client*, via `survos/media-bundle`) — the application's reference to it.

`MediaRecord` remains, and is a different thing: the grouping id a client sends as
`media_record_key`, which claims hang off. It is not a second copy of `Asset`.

**mediary does not require `survos/media-bundle`.** If it ever does again, something has been
wired backwards — the server must not depend on its own client library.

### Shared vocabulary lives in `survos/data-contracts`

Anything both sides must agree on is defined once, in a package neither owns:

| Concern | Class |
|---|---|
| Asset id from a URL — `xxh3`, 16 hex, **not** reversible | `Util\MediaIdentity` |
| imgproxy-style key — URL-safe base64, **reversible** | `Util\MediaKeyService::keyFromString()` |
| Sidecar/bucketed path — `o/<1 hex>/<2 hex>/<key>.<ext>`, key kept as filename | `Util\MediaKeyService::archivePathFromKey()` |
| Batch wire format | `Dto\BatchPayloadDto`, `Dto\BatchItemDto` |
| Sync protocol keys | `Vocabulary\MediaSyncKeys` |
| Preset names (`small`, `ai`, …) | `Vocabulary\MediaPreset` |

mediary's own archive layout for originals (`orig/<2>/<2>/<long hex>.<ext>`) is a separate scheme
from `archivePathFromKey()` above — don't conflate them.

These used to live in media-bundle, which meant the server imported the client to understand its
own wire format. Producer and consumer now derive the same values without either depending on the
other, so they cannot drift.

---

## How images arrive

**The supported path is the client's Media workflow, not `media:sync`.**

A client persists a `Media` row; its initial place declares `next: [dispatch]`, so state-bundle
queues the dispatch on `postFlush`; the consumer batches URLs to mediary's `/batch` endpoint with
a `callback_url`. Registering the row is the only manual step.

```php
// in the client — survos/media-bundle
$media = $mediaRegistry->ensureMedia($imageUrl);
$em->flush();   // everything after this is unattended
```

`media:sync` still exists for pushing a single URL by hand while debugging. A pipeline that calls
it is doing it the old way.

### The Asset workflow

`new → archive → archived → info → informed → triage → triaged → analyze → analyzed → complete`,
with `iiif`, `local_ocr`, `ai_ready`, `failed` and `deleted` off to the side. See
`src/Workflow/AssetFlow.php`, which is the authority; the diagrams below lag it.

Most images arrive with **no AI tasks for a given step** — an ssai postcard has an `observe` task
on the front and a Mistral OCR task on the back, and an ordinary museum object has neither. That
is expressed as `next: []` on the place rather than as a dedicated PHP handler: a few lines of
attribute, and the chain simply stops there instead of running AI over an empty list.

![Media Workflow](assets/images/MediaWorkflow.svg)
![Thumb Workflow](assets/images/ThumbWorkflow.svg)

### Bucketing

Each collection declares its approximate image count (within an order of magnitude). Over ~1M
images the archive uses an 8³ directory structure, otherwise 8², so a complete metadata fetch
takes 64 API calls instead of 512. Files distribute evenly within the buckets.

---

## JSON-RPC

### Application API — `POST /api/v1`

Structured endpoints via `otezvikentiy/json-rpc-api`. Sidecar storage is here:

```bash
curl -X POST https://mediary.survos.com/api/v1 \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"sidecarGet",
       "params":{"id":"<assetId>","task":"observe"},"id":"1"}'
```

- `sidecarGet` — `{found, data, path}`; `found: false` is a normal cache miss, not an error
- `sidecarPut` — `{stored, path}`; overwrites deliberately

`SidecarService` used to live in media-bundle and be injected directly by client apps, making
every app a second writer into mediary's bucket namespace, with its own S3 credentials and its own
copy of the path convention. mediary now owns the store and apps ask for it — which also means a
Redis or batching layer can appear here without any client changing.

There is deliberately no `remember()` over the wire: its compute-if-missing contract takes a
producer callable, which does not survive a network boundary, and the paid AI call belongs to the
side that wanted the answer. Clients keep that branch locally.

### MCP — `POST /_mcp` (dev/test only)

```bash
SID=$(curl -s -D /tmp/h -o /dev/null -X POST \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' \
  https://mediary.wip/_mcp; grep -i '^mcp-session-id' /tmp/h | tr -d '\r' | cut -d' ' -f2)

curl -s -X POST -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' -H "Mcp-Session-Id: $SID" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' https://mediary.wip/_mcp | jq
```

The old `/tools` route belonged to a package that is no longer installed and 500'd on every
request. Adding a tool: [doc/JSONRPC.md](doc/JSONRPC.md).

---

## Probe API (polling fallback)

When callbacks cannot get through — a local dev tunnel is down — poll over JSON-RPC.

```bash
curl -s -X POST https://mediary.survos.com/api/v1 \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","method":"probeAssets",
       "params":{"ids":["<id1>","<id2>"],"token":"$MEDIARY_API_TOKEN"},"id":"1"}' | jq
```

Returns `{assets: [...], found: N, missing: [...]}`. Unknown ids come back in `missing` rather
than being silently dropped, so a short array cannot pass for a complete answer.

Each asset carries the top-level fields (`id`, `source`, `marking`, `meta`), `thumbs` and full
`variants`, `context` (where OCR/AI enrichment lives), `children` (page/OCR derivatives), and
convenience mirrors `ocr` / `ai` from `context`.

> **The REST routes this replaces did not work for API clients.** `GET /fetch/media/{id}` and
> `POST /fetch/media/by-ids` are not in security.yaml's PUBLIC_ACCESS list — only
> `^/[^/]+/batch$`, `^/api/v1$` and `^/api/claim-store/` are — so everything else falls through
> `- { path: ^/, roles: ROLE_USER }` and 302s to `/login`. Worse than a clean 401: HTTP clients
> follow the redirect, so the caller gets **200 with the login page's HTML** and fails while
> parsing JSON, which reads as though mediary returned garbage rather than as a refusal. Both
> transports were served by the same AssetProbeService, so the JSON-RPC rows are identical.
>
> `probeAssets` requires the token and fails closed when `MEDIARY_API_TOKEN` is unset — a probe
> returns titles, OCR text, AI output and storage URLs, so it reads the archive's contents.

## Running it

```bash
git clone git@github.com:survos/mono.git
git clone <mediary> && cd mediary
composer install
../mono/link .        # ../mono/link --rollback && composer install  to test real releases
```

### Workers

Every transport is `doctrine://` today (see `config/packages/messenger.yaml`), so a queue is a
table and there is no rabbitmq to purge.

```bash
bin/console messenger:stats            # queue depths -- check this FIRST when nothing is happening
bin/console messenger:consume asset.archive asset.info asset.iiif asset.triage asset.analyze asset.ai.task
bin/console messenger:consume webhook  # outbound client callbacks
```

**The `webhook` transport needs its own consumer.** Callbacks that nobody consumes look exactly
like a mediary that never answered: clients sit at their pre-callback status indefinitely, with
the evidence in a queue table rather than a log. `messenger:stats` is the tell.

```bash
bin/console dbal:run-sql "delete from messenger_messages where queue_name='failed'"
bin/console dbal:run-sql "delete from messenger_messages"
```

### Deploy

```bash
dokku storage:mount mediary /mnt/volume-1/project-data/mediary/public:/app/public
chown -R 32767:32767 /mnt/volume-1/project-data/mediary
```

### Reset local data

```bash
rm -f var/data.db && bin/console d:sch:update --force
```

---

## Recap

* A client registers with mediary and receives a code (its API key and storage root).
* The client's Media workflow pushes URLs to `/batch`; mediary returns a status per URL and queues
  the work.
* mediary downloads each image to a cache dir, uploads it to the archive (`archive.storage`) and
  to local storage, then drops the temp file.
* Claims sent alongside the batch are ingested and **flushed** — that flush was missing, so source
  claims were silently discarded on every batch.
* The thumbnail workflow derives variants from local storage (faster than remote).
* On completion mediary calls the client's webhook so it can update its rows.
* Clients may also poll (see Probe API). That is for debugging; overuse will overwhelm the server.
* Once variants exist, the local-storage copy can be deleted; it is re-downloaded if filters change.

![Database Diagram](./assets/images/db.svg)

---

> **Stale below this line.** The remaining notes describe the pre-mediary design
> (LiipImagineBundle on-the-fly thumbnails, a `/handle_media` callback, `/ui/account_setup`,
> `sais:queue`). None of those routes or commands exist — `debug:router` and `bin/console list`
> are the truth. Left in place rather than renamed, so nobody mistakes it for current API docs.

## Ideas / reading

* EasyOCR (very slow)
* ONNX runtime for OCR, CPU/GPU-optimized; PaddleOCR (Chinese/Korean)
* Azure Document Intelligence (slow and expensive)
* GropID, Digital Humanities
* https://medium.com/@robi.tomar72/deepseek-ocr-just-did-the-impossible-and-the-entire-ai-world-is-shook-4020afb28956
* https://medium.com/devsphere/integrating-php-with-opencv-for-image-recognition-c83a04329da6
* https://discuss.pixls.us/t/stag-an-open-source-tool-for-automatic-image-tagging/48369
* Thumbnails on S3: https://medium.com/@laurentmn/optimizing-image-handling-in-symfony-with-liipimaginebundle-pro-tips-use-cases-7f55819deb80
