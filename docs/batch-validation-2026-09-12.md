# Local provider-batch validation, 2026-09-12

Local `MEDIARY_AI_BATCH` was 0, deliberately left off after the original Mistral 402 access denial. Local access now works. Enabled in `.env.local`.

30 supplied-ALTO Sunday Telegram pages (1914-11-29, cron-america/sn85059732) passed Mediary's normal ai_task transition, Mistral batch submission, polling, private S3 archival, claims and unlock. No image OCR bought. First 12 jobs contained one page each: RabbitMQ prefetch 1 prevented the batch handler retaining multiple deliveries. Explicit transport prefetch_count 500 fixed it; AiBatch 14 contains the remaining 18 requests, all applied. Worker fetch-size is also 500. No repeated requests.

The poller now retries failed archival before applying terminal jobs. It requests private visibility, reads bytes back, records SHA-256/bytes/lines/verification timestamp in AiBatch.meta.resultArchive, then records savedResultPath. Failed jobs without output can still unlock assets. `media:ai-batch:verify ID` checks durable results without contacting the provider. IDs 2 and 14 verified successfully. Failure/retry regression test passes.

OpenAI vision uses the existing media:batch-observe path, distinct from ai_task. The command now accepts explicit --asset-id selections and prefers existing AssetPresigner URLs over third-party originals. Local AiBatch 15 has three archived Cleveland images, scope batch-validation/openai-vision; submitted as batch_6aa5cd8166348190ab5797ec4a670794. Its completion remains to be checked. ObserveTask itself does not yet implement BatchableTaskInterface; do not describe this as transition-based OpenAI vision coverage.

The new periodical task/queue adapter remains local pending a shared ai-workflow package release. This deployment contains the reusable backup, queue and bounded vision-test fixes, not the unpublished periodical task. Harvest prepares/imports data; Mediary owns AI execution.
