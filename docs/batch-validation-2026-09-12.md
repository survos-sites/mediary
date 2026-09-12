# Local provider-batch validation, 2026-09-12

Local `MEDIARY_AI_BATCH` was 0, deliberately left off after the original Mistral 402 access denial. Local access now works. Enabled in `.env.local`.

30 supplied-ALTO Sunday Telegram pages (1914-11-29, cron-america/sn85059732) passed Mediary's normal ai_task transition, Mistral batch submission, polling, private S3 archival, claims and unlock. No image OCR bought. First 12 jobs contained one page each: RabbitMQ prefetch 1 prevented the batch handler retaining multiple deliveries. Explicit transport prefetch_count 500 fixed it; AiBatch 14 contains the remaining 18 requests, all applied. Worker fetch-size is also 500. No repeated requests.

The poller now retries failed archival before applying terminal jobs. It requests private visibility, reads bytes back, records SHA-256/bytes/lines/verification timestamp in AiBatch.meta.resultArchive, then records savedResultPath. Failed jobs without output can still unlock assets. `media:ai-batch:verify ID` checks durable results without contacting the provider. IDs 2 and 14 verified successfully. Failure/retry regression test passes.

OpenAI vision uses the existing media:batch-observe path, distinct from ai_task. The command now accepts explicit --asset-id selections and prefers existing AssetPresigner URLs over third-party originals. Local AiBatch 15 has three archived Cleveland images, scope batch-validation/openai-vision; submitted as batch_6aa5cd8166348190ab5797ec4a670794. Its completion remains to be checked. ObserveTask itself does not yet implement BatchableTaskInterface; do not describe this as transition-based OpenAI vision coverage.

The new periodical task/queue adapter remains local pending a shared ai-workflow package release. This deployment contains the reusable backup, queue and bounded vision-test fixes, not the unpublished periodical task. Harvest prepares/imports data; Mediary owns AI execution.

## Final local findings

OpenAI batch 15 failed three requests with invalid_image_url/404: stale archive keys on these older Cleveland asset records. It exposed two shared ai-batch-bundle defects: error_file was ignored, and HTTP error bodies were parsed as successes. Fixed in mono, released as ai-batch-bundle 2.28.3. Archives now reject missing result lines. Empty observe runs are marked failed; appliedCount counts assets and meta.appliedClaims counts claims.

A bounded original-source retry, AiBatch 16 / batch_6aa5ceb92de481908fff04b595430d39, completed 3/3, applied 3/3 and recovered 3,509 bytes from S3 with matching checksum, without provider access. Usage: 8,802 input, 264 output tokens. Failed batch 15's three error records are also archived (1,014 bytes).

All 30 Mistral pages used 222,397 input / 28,878 output tokens. Batch 14's durable copy has 18 lines, 67,690 bytes. No paid image OCR was used. Previous Mistral OCR image test is AiBatch 1, one page; this is not a larger OCR test.

Exported 30 claims via the existing claims exporter, then DatasetInfo normalize/enrich/folio transitions and ink:folio:refresh. Local Sunday Telegram now has 174 readable article records. These groupings remain unreviewed, not ground-truth segmentation. Local URL: http://ink.wip/sunday-telegram .
