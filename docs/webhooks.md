# mediary webhooks

mediary **sends** `asset.analyzed` when an image finishes its workflow. It receives no webhooks.

The full cross-service contract — naming, signatures, how to write a receiver — lives in
[kit-bundle/docs/webhooks.md](../vendor/survos/kit-bundle/docs/webhooks.md). This page covers
only what is specific to mediary.

---

## What gets sent, and when

`App\Workflow\AssetWorkflow` fires on completion; `App\Service\AssetNotifier::notify()` builds
the payload and dispatches a `SendWebhookMessage`. Nothing HTTP happens in the transition.

Destination is per asset: `context['callback_url']`, recorded at registration time from the
client's `MEDIA_CALLBACK_URL`. An asset with no callback URL is simply not announced — that is
the pre-2026-08 state of most of the 608k archived assets.

The payload is whatever `AssetNotifier::mediaState()` says, which is also what `/{client}/batch`
returns, under the historical spellings each side already uses (`marking`/`archiveUrl` on the
webhook, `status`/`s3Url` on the batch row). One set of facts, two renamings — see the class
docblock for why that stayed rather than being unified.

---

## Configuration

```bash
# Signing key. Every subscriber needs the SAME value in its own environment.
# Empty ⇒ AssetNotifier refuses to send rather than send unsigned.
MEDIARY_WEBHOOK_SECRET=

php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # generate one
```

`config/packages/webhook.yaml` enables the component (sender services only — no `routing:`
block, because mediary receives nothing). `config/services.yaml` decorates `webhook.transport`
so failed deliveries are actually noticed, and routes `.wip` subscribers through the Symfony
CLI proxy.

Delivery runs on its own `webhook` transport, so a 608k-asset replay cannot bury the `meili` or
`cache_warm` queues, and a subscriber that is down cannot stall anything else.

---

## Running it

```bash
bin/console messenger:consume webhook -v          # deliver
bin/console messenger:failed:show                 # what didn't land
bin/console media:replay-webhooks --client=harvest --limit=100
```

`media:replay-webhooks` **queues**; it does not deliver. That is deliberate — the backfill is
~608k assets, and a command that delivered inline would die two hours in on one subscriber's
timeout instead of letting the worker retry per message.

### After a subscriber moves its endpoint

`callback_url` is stored per asset, so changing it in the subscriber's `.env` does nothing for
assets already registered. Re-registering overwrites it (last-writer-wins); for the backlog:

```bash
bin/console webhook:migrate-callback-urls --dry-run
bin/console webhook:migrate-callback-urls --from=/media/callback --to=/webhook/mediary
```

This was needed once already, when the receiving endpoint moved off the unauthenticated
`/media/callback`. Host is left alone — only the path is rewritten, so every subscriber migrates
in one pass.

---

## Removed

`src/Webhook/SaisHookRequestParser.php`, `src/RemoteEvent/SaisHookWebhookConsumer.php` and the
`sais_webhook::` route were deleted in survos-sites/mediary#8. They arrived with the
`initial version (was sais)` commit as unfinished `make:webhook` scaffolding: the parser
deserialized into an `App\Entity\Media` that does not exist, the consumer imported a
`SaisHookRequestMapper` that does not exist, the route named a `Symfony\Bundle\WebhookBundle\…`
class that does not exist, and nothing configured `framework.webhook.routing`, so none of it was
ever reachable. It was also receive-side code in the one app here that only sends.
