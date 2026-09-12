# AI batching in mediary

Status, 2026-09-11: **built and wired, off by default** (`MEDIARY_AI_BATCH=0`). The routing →
batch → release → sync loop is verified live; the submit → poll → apply loop waits on Mistral
batch access (see "Mistral access" below). The sync path stays for debugging and interactive
calls.

## Why

Provider batch APIs cost **half** as much, use a separate and much larger rate-limit pool, and
take away timeout pressure. Results arrive in minutes to 24 hours. Every bulk run we have is
exactly that shape: harvest datasets, Soviet Life's ~5,000 pages, RappNews next.

| Task | Sync | Batch |
|---|---|---|
| `ocr_mistral` (Mistral OCR 4.1) | $4 / 1k pages | **$2 / 1k pages** |
| `observe` (gpt-4o-mini vision) | $0.0008 / image | **$0.0004 / image** |

## What exists

| Piece | Where | State |
|---|---|---|
| **ai-batch-bundle** | `mono/bu/ai-batch-bundle` (`survos/ai-batch-bundle`) | The lifecycle: an `AiBatch` entity, `BatchCapablePlatformInterface`, OpenAI and Anthropic clients, `AiBatchBuilder`, `PollBatchesTask` (a cron every 2 minutes via Scheduler), and the `PollBatchesMessage`/`ApplyBatchResultsMessage` messages. `BatchRequest` assumes a chat prompt. **No Mistral**, and its docblock still says Mistral has no batch API. A prototype, not really tested. |
| **mediary `media:batch-observe`** | `src/Service/MediaBatchObserveService.php` + `src/MessageHandler/{Poll,Apply}BatchResultsMessageHandler.php` | Batches OpenAI vision "observe" over assets. The apply step records `observe:*` claims under the same keys as the sync path. The OpenAI client and the observe task are both hard-coded. It sits beside the per-asset path, not inside it. |
| **harvest `dataset:batch-ai`** | `harvest/src/Service/DatasetBatchAiService.php` | Batches OpenAI `image_enrich`/`dense_summary` over a folio's `obj` rows. Its "Stage 1: Mistral" plan (`harvest/docs/plan-2026-06-28-batch-ai.md`) was never built. |
| **survos-sites/ai-batch** | GitHub | An OpenAI postcard playground, useful as a reference for monitoring, fetching and applying results. |
| **lingua** | `lingua/src/Message/TranslateBatchMessage.php` | The lesson below, and `--fetch-size`. |

Three batch paths, each with its own provider and task, and none of them sits where mediary
actually runs AI (`AssetWorkflow::onAiTask` → `runNextAiTask` → `AssetAiExecutor::run`).

## Two ways Messenger can batch, and which one fits here

1. **Fetch several at once, at the transport** (`messenger:consume … --fetch-size=N`, Symfony
   8.1). The worker reads N messages per query or round trip. This is **throughput only**: each
   message is still handled on its own. lingua uses `--fetch-size=8` against a Doctrine
   transport. It's worth adding to any high-volume worker, but it groups nothing.
2. **Group in the handler** (`BatchHandlerInterface` + `BatchHandlerTrait`). The handler buffers
   messages and processes them together at N, or when the worker goes idle (the worker calls
   `flush(force: true)`). Each message keeps its own ack/nack. This is the tool for "one provider
   job from many assets", and media-bundle's `MediaWorkflowDefinition` names it as where
   batching belongs.

**The trap, found twice:** you can't put a batch handler on state-bundle's `TransitionMessage`.
`WorkflowHelperService::handleTransition` is a bare `#[AsMessageHandler]` that handles
`TransitionMessage` from *every* transport. A second, batching handler would run **as well**,
and every task would run twice. harvest's `BatchResizeRequests` hit this and was deleted.
lingua worked around it by grouping when it *dispatches* (`TranslateBatchMessage`), because
its producer already knows the whole group.

mediary's producer doesn't know the group: assets reach `ai_ready` one at a time, whenever
archiving and inspection finish.

**What we built: state-bundle batch transitions.** `ai_task` is declared
`#[Transition(..., batch: 500)]`. On the way out, state-bundle's `BatchTransitionMiddleware` turns
its `TransitionMessage` into a `BatchedTransitionMessage`. That class is deliberately not a
subclass, so it has exactly one handler: `BatchTransitionHandler`, a `BatchHandlerInterface`.
The transition keeps its own queue (`asset.ai.task`), and every dispatch site batches unchanged.
The same mechanism is open to harvest and ssai (observe), and to any workflow.

## Flow

```
asset → ai_ready ──next: ai_task──► BatchTransitionMiddleware ──BatchedTransitionMessage──► asset.ai.task
                                                                                              │
   BatchTransitionHandler (500, or 5 s idle): one query, can() filter, then ONE event ◄───────┘
     └─► AssetAiBatchSubmitter (#[AsBatchTransitionListener('asset', 'ai_task')])
           per asset, next task = aiQueue[0]:
           ├─ not batchable / file:// or s3:// / force|sync override / sidecar cached / 4xx from provider
           │     → release(): re-dispatched as a plain ai_task (CONTEXT_UNBATCHED) → sync, one per asset
           └─ batchable → one provider job per (provider, endpoint, model, task)
                 → AiBatch row {kind: asset_ai_task, assets: [...]}; asset.context.ai_batch = {task, batch, job}
     └─► apply ai_task with ['batched' => true] → AssetWorkflow::onAiTask: shift the task, aiLocked = true
         (onCompleted → advanceAiQueue stops at the lock)
                                                                                              │
   PollBatchesTask (every 2 min) → PollBatchesMessageHandler: client by AiBatch.provider (BatchClients),
     status → row; on ANY terminal status archive raw lines to S3 → ApplyBatchResultsMessage
                                                                                              │
   ApplyBatchResultsMessageHandler → AssetAiBatchApplier, per asset:
     task->batchResult(body) → AssetAiExecutor::record() (SAME sidecar + claims + search sync as sync)
     → AssetWorkflow::completeBatchedTask(): aiCompleted, unlock, advanceAiQueue → ai_task | ai_done
     → complete → client webhook
```

Clients such as harvest change nothing. harvest's enrich gate already waits for every
dispatched asset to reach a final state, so it waits for a batch just as it waits for a sync run.

**Nobody stays locked.** A failed line, a missing line, or a job that failed, timed out or was
cancelled all end the task as failed, exactly as a sync exception does, and the asset moves on.
If the apply itself throws, the batch is handed off again on every poll until it is `applied`.
A completed job whose results can't be read (S3 and the provider both down) throws, so it retries
rather than failing 500 assets.

## Contracts (implemented)

**ai-workflow-bundle: `BatchableTaskInterface`** (a task opts in; plain arrays, no dependency on
ai-batch-bundle):
```php
public function batchProvider(): string;                                  // 'mistral' | 'openai' | 'anthropic'
public function supportsBatch(WorkflowSubjectInterface $s): bool;         // public http(s) input only
public function batchRequest(WorkflowSubjectInterface $s): array;         // {endpoint, body} -- body as run() sends it
public function batchResult(WorkflowSubjectInterface $s, array $responseBody): TaskResult;
```
`OcrMistralTask` implements it. `run()` and the batch path share `payload()` and
`normalizeResponse()`, so the claims are identical whichever way a page ran.

**ai-batch-bundle:**
- `MistralBatchClient`: `/v1/batch/jobs` for any endpoint. Inline `requests` below 10,000,
  otherwise a `purpose=batch` `.jsonl` upload. `model` and `endpoint` are set per job, so one job
  covers one (endpoint, model) pair. Results come from `output_file`, then `error_file`.
- `BatchRequest::raw(customId, endpoint, body)` + `toMistralLine()`. The chat constructor is unchanged.
- `BatchJob::fromMistralArray()`: status normalized. `SUCCESS` means the job ran to the end; failed
  lines are counted and sit in `error_file`.
- `BatchResult::fromMistralLine()`, `fromProviderLine()`, and `$body`: the full response body, so
  a task parses a batch result with its sync parser.
- `BatchClients`: provider name → client (`tacman.ai_batch.client` tag, `provider` attribute).

**state-bundle:**
- `#[Transition(batch: N)]`, `BatchedTransitionMessage`, `BatchTransitionHandler`.
- `#[AsBatchTransitionListener]` / `BatchTransitionEvent`, with `skip()` (apply inline, unbatched)
  and `release()` (re-dispatch alone).
- `survos_state.batch_enabled`, `batch_size`, `batch_idle_timeout`.

## Policy and debugging

- **`MEDIARY_AI_BATCH=1`** (→ `survos_state.batch_enabled`): the global switch. Off, the middleware
  swaps nothing and every `ai_task` is its own message, exactly as before batching existed.
- **Batchable tasks** are the ones implementing `BatchableTaskInterface`. Start with `ocr_mistral`.
  Scopes can opt out.
- **Sync always wins for** `media:task …` / `ai:workflow:run …`, the HTTP `/media/ai/from-url`,
  and any asset whose task override says `force` or `sync`. Those are the debugging paths.
- **Group size:** `batch: 500` on `ai_task`, plus flush-on-idle (`batch_idle_timeout`, 5 s), so
  a trickle still goes out when the queue drains.

## Hardening the prototype

- **Stuck assets:** done for every job the provider reports on. Failed, cancelled and timed-out
  jobs go through the applier like finished ones, and their assets end the task as failed and
  move on. Still open: a reaper for an asset whose `ai_batch` marker points at a batch row that is
  gone, or at a job the provider no longer knows.
- **Idempotent apply:** `AiBatch.appliedCount`, and `ClaimIngestor` replaces per
  (subject, source, scope). A re-applied batch changes nothing.
- **Partial failure:** only the failed lines are retried. The error file is archived next to the
  output in S3.
- **Cost:** record estimated and actual cost per batch; the `/_ai/batches` UI shows it.
- **URLs the provider must fetch:** prefer mediary's archived copy (`asset.s3Url`) when it's
  public, otherwise `originalUrl`. **Unverified:** whether Mistral's batch workers fetch outside
  image URLs (e.g. Internet Archive IIIF). The first probe settles it.

## Workers

Nothing new. The `ai` worker (`messenger:consume asset.ai.task`) now also receives
`BatchedTransitionMessage`s, and `scheduler` drives `PollBatchesTask`. A group waits
unacknowledged for at most `batch_idle_timeout` plus the submission call, well inside RabbitMQ's
30-minute delivery timeout. Released assets go back to the same queue one message each.

## Mistral access

Batch creation answered **402 "You do not have access to this service"** for both chat and OCR,
inline and file, even after $10 of credit (2026-09-11). Sync calls and *listing* jobs worked. In
the admin console, the org's Subscription page shows the **Free** plan with **API pay-as-you-go:
Enable** not yet turned on. Batch jobs are a paid-tier service; credit alone doesn't unlock them.
Probe once it's on (one page, ~$0.002):
`php <scratch>/mistral-batch-probe.php submit`, then `check <job>`, then `results <job>`. That pins
down the output line format and whether Mistral's batch workers fetch Internet Archive IIIF URLs.

## Found on the way (state-bundle)

- `AsyncQueueRoutingMiddleware` has never run in any app. Its bus-middleware prepend is in
  `loadExtension()`, after FrameworkBundle has built its buses, so it shows in `debug:config` and
  is then removed as unused. Switching it on would change routing everywhere; left as it is.
- `framework.messenger.buses.*.middleware` does not deep-merge. Every bundle that prepends a list
  replaces the others' (Inspector APM's won in mediary). So `BatchTransitionMiddleware` goes onto
  the buses through a compiler pass (`BatchTransitionMiddlewarePass`), not config.

## Slice order

1. ✅ **ai-batch-bundle:** `MistralBatchClient`, `BatchRequest::raw`, Mistral job/result mapping,
   `BatchClients`. ⏳ The one-page real job, blocked on Mistral pay-as-you-go.
2. ✅ **ai-workflow-bundle:** `BatchableTaskInterface`; `OcrMistralTask` implements it.
3. ✅ **mediary:** `AssetAiBatchSubmitter`, `AssetAiBatchApplier`, provider-agnostic poll/apply,
   `AssetAiExecutor::record()`, `onAiTask`/`completeBatchedTask`, the switch. Verified live:
   route → batch → release → sync, and batching off.
4. **Feb 1961, all 83 pages, as one batch (~$0.17).** Compare the claims with the sync run of
   pp. 49–51, and the articles with `ocrSource: vault`.
5. **Soviet Life in full (~$10)** is harvest's step 7.
6. **Later:** `observe` as a `BatchableTaskInterface` (OpenAI), folding in `media:batch-observe`;
   retire harvest's `dataset:batch-ai` for anything mediary runs. A reaper for assets whose
   `ai_batch` marker points at a batch row that no longer exists.
