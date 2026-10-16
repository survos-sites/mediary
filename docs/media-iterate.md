# Media iteration and model comparison

`media:iterate` selects local Doctrine entities, in identifier order, and dispatches
`App\Event\MediaIterationEvent` once per entity. It defaults to three assets. The
iterator is independent of AI, and does not apply workflow transitions or flush.

```bash
# Preview a bounded selection; no listeners or provider calls
bin/console media:iterate --filter dataset=omeka/wej --limit 3 --dry-run

# Compare the current Observe prompt on OpenAI and Gemini 2.5 Flash-Lite
bin/console media:iterate --filter dataset=omeka/wej --limit 3 --compare

# New Google accounts: explicit 3.5 Flash-Lite profile, no silent model fallback
bin/console media:iterate --filter dataset=omeka/wej --limit 3 --compare \
  --models openai-mini,gemini-flash-lite-3.5

# One asset, optionally include Ministral
bin/console media:iterate --filter id=YOUR_16_CHAR_ID --limit 1 --compare \
  --models openai-mini,gemini-flash-lite,ministral-8b \
  --output var/comparisons/one-image.jsonl

# Any mapped entity with a single string/integer identifier
bin/console media:iterate --entity MediaRecord --limit 10 --page-size 5
```

Repeat `--filter field=value` to AND exact matches. Only mapped scalar fields are
accepted; association paths, raw DQL, JSON filters and composite identifiers are
deliberately unsupported. `field=null` means SQL NULL. Limits must be positive.
Keyset pagination avoids offsets and retains only one page of root entities;
entities are detached after listeners return. Listeners that want persistence
must explicitly perform it before returning.

## Comparison

The optional `CompareMediaListener` adapts Assets with the existing
`AssetAiExecutor::subjectFor()` (including known catalogue facts), then obtains the
existing task's chat payload from `BatchableTaskInterface::batchRequest()`. There
is no copied Observe prompt and no separate Observe implementation per provider.
Other chat-completions image tasks can be selected with `--task`.

The listener downloads each image once and sends identical bytes to all models.
It records the actual dimensions and SHA-256; it does not resize images. OpenAI
retains the task's image detail setting (`low` for Observe), while other providers
use their own image tokenization. Calls use Symfony HttpClient: OpenAI/Mistral chat endpoints and Gemini's native
generateContent endpoint, preserving text and image bytes while avoiding the
installed Symfony bridges' model-catalog limitations. This does not
add native Gemini support to the production pipeline.

Calls are synchronous and sequential, with a 4,096-output-token cap. Elapsed time
includes the complete HTTP request/response, excluding the shared image download
(recorded separately). This is a smoke comparison, not a statistically reliable
latency benchmark. Requests do not submit provider batches, record claims, populate
sidecars, change AI queues, or advance workflows.

Results are flushed per entity to a new JSONL file under `var/comparisons/` (or
`--output`). Each model record includes prompt, image metadata, raw text, parsed
JSON (including Markdown fences accepted by the task parser), finish reason, actual returned model, token usage, elapsed milliseconds,
estimated synchronous cost, rate snapshot, and a hypothetical 50%-discount batch
cost. Missing usage produces an unknown cost, never zero. Output/thinking tokens
are priced using reported completion tokens. Native Gemini accounting adds
`candidatesTokenCount` and `thoughtsTokenCount` and preserves `usageMetadata`
for inspection; thought signatures are not output text. Cached
input tokens use the configured cached rate. Estimates exclude retries, tools,
storage, taxes and downstream tasks. Invalid JSON, truncated output and provider
errors are recorded and yield a nonzero exit code after the remaining comparisons.
JSON validity does not establish factual accuracy or compliance with every task
field. HTTP error bodies are omitted because providers may echo request data.

Profiles and dated USD rates live in `config/services/media_comparison.yaml`.
Add `GEMINI_API_KEY` to `.env.local`; `GOOGLE_API_KEY` is a fallback. OpenAI and
Ministral use `OPENAI_API_KEY` and `MISTRAL_API_KEY`. A missing selected credential
fails before any paid calls. Existing output files are never overwritten.

Google keys: https://aistudio.google.com/apikey

Google currently limits Gemini 2.5 models to previous users of those models.
The `gemini-flash-lite` profile deliberately keeps the original 2.5 model/pricing;
use `gemini-flash-lite-3.5` explicitly for a new account and its different rates.
Provider access still depends on your account and billing setup.

## Add another listener

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\MediaIterationEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class InspectMedia
{
    public function __invoke(MediaIterationEvent $event): void
    {
        // Inspect $event->entity; optionally check $event->context first.
        $event->results['inspect'] = ['status' => 'ok'];
    }
}
```

The event and iterator are app-local reusable building blocks. Other applications
can adopt them and supply listeners; packaging them in a shared Survos bundle is
a separate change. Remote API Platform pagination is not implemented in this
version. Existing `media:task` and `ai:task:run` remain the production task-running
commands; their persistence semantics differ from this comparison.

### Controlled JSON-mode comparison

`--models ministral-8b-json` uses the same Ministral model and prompt with
`response_format: {type: json_object}` enabled. Keep this separate from the
original `ministral-8b` baseline, which preserves Observe's prompt-only JSON
request. JSON mode addresses syntax; required fields and factual correctness
still need validation. An optional profile `response_format` is passed to the
provider (translated to native generation configuration for Gemini).

For a controlled three-provider JSON-mode run on one newspaper snippet:

```bash
bin/console media:iterate --filter id=ASSET_ID --limit 1 --compare \
  --models openai-mini-json,gemini-flash-lite-3.5-json,ministral-8b-json
```

These profiles enforce JSON syntax, not a typed Observe schema. They leave the
existing prompt, 512-pixel imgproxy preset, routing and follow-up behavior intact.
A valid routing-only result can legitimately request high-resolution observation;
its cost is not the cost of a completed newspaper transcription.

Crop a snippet from an Asset before sending it to the models:

```bash
bin/console media:iterate --filter id=faacbfb299f5684c --limit 1 --compare \
  --crop=575,710,372,512 \
  --models openai-mini-json,gemini-flash-lite-3.5-json,ministral-8b-json
```

`--crop` is x,y,width,height in original-image pixels. The comparison listener
uses the existing imgproxy URL builder to crop the archive/original image, then
fits it inside 512×512 (without enlargement), quality 80 WebP. It records crop
coordinates, returned dimensions and image hash. This is a comparison-only
runtime transformation; it does not create or modify database assets.
The example is “Health Program Grows at S.H.S.” from Rappahannock News,
November 3, 1949, page 1.

An unregistered image can be compared without inserting an Asset:

```bash
bin/console media:iterate --image-url='https://example.org/snippet.jpg' --compare \
  --models openai-mini-json,gemini-flash-lite-3.5-json,ministral-8b-json
```

This visits one transient Asset and uses the same imgproxy Observe thumbnail.
It cannot be combined with database filters or a different entity class.
For OCR baselines, distinguish local Tesseract timing (including process/model
startup, excluding image download) from precomputed provider OCR (no generation
time measured). Provider OCR engines and coordinate systems vary; LoC's ALTO
for the tested 1894 snippet reports Tesseract 5.4.1 plus ndnp-open-ocr 1.2.0.
