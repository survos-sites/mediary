<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Messenger\SendWebhookMessage;
use Symfony\Component\Webhook\Subscriber;

/**
 * Everything mediary tells a client about an asset, in one place.
 *
 * There were three copies of this: BatchController's inline `$media[]` row,
 * AssetWorkflow::fireWebhook(), and ReplayWebhooksCommand's hand-rolled
 * duplicate of fireWebhook. They drifted, and the drift was the bug —
 * dimensions existed only on the webhook, so a client that only ever called
 * `/batch` (which is every client, since no one had wired a callback_url)
 * could never learn an image's width. See survos-sites/mediary#7.
 *
 * The two payloads deliberately keep their historical spellings — the batch
 * response says `status`/`s3Url`, the webhook says `marking`/`archiveUrl` —
 * because changing them would break every deployed client for no gain. What
 * converges here is the SET of facts, built once by mediaState(); the public
 * methods only rename. media-bundle's MediaUpdate::fromBatchRow()/fromWebhook()
 * are the mirror of this class and normalise both spellings back to one shape.
 */
final class AssetNotifier
{
    /**
     * Keys lifted out of Asset::$context for clients.
     *
     * A whitelist, not the whole blob: context also holds mediary's internal
     * bookkeeping (aiQueue scratch, callback_url itself, download paths) that
     * no client should see or store. `info` is imgproxy Pro's /info response —
     * dimensions, faces, average colour, exif — and is the single largest thing
     * clients have never been sent; ~2.5 KB per asset, which is why it rides in
     * context rather than being shredded into top-level keys.
     */
    private const CONTEXT_KEYS = [
        'info',
        'info_source',
        'sha256',
        'ocr',
        'ocr_chars',
        'thumbhash',
        'colors',
        'phash',
        'path',
        'tenant',
        'image_id',
    ];

    /** The `Webhook-Event` name clients match on. Mirrors MediaWebhookRequestParser::EVENT_ASSET_ANALYZED. */
    public const string EVENT_ASSET_ANALYZED = 'asset.analyzed';

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface     $logger,
        /**
         * Shared HMAC secret. Every subscriber is one of our own apps, so one secret per
         * SENDING service is the unit — a per-subscriber secret would need a subscriber
         * registry that mediary does not have (`Asset::$clients` is a bare string array).
         * Revisit if a third party ever subscribes.
         */
        #[Autowire('%env(default::MEDIARY_WEBHOOK_SECRET)%')]
        private readonly ?string             $secret = null,
    ) {
    }

    /**
     * One row of the `/{client}/batch` response.
     *
     * Note this is generated at REGISTRATION time — before the archive and
     * /info steps run — so on a first sync most of these are legitimately null.
     * That is exactly why the callback exists; the batch response can only ever
     * report what mediary already knew when the client asked.
     *
     * @return array<string,mixed>
     */
    public function batchRow(Asset $asset, bool $dispatched): array
    {
        $state = $this->mediaState($asset);

        return [
            'originalUrl'    => $state['originalUrl'],
            'mediaKey'       => $asset->id,
            'status'         => $state['marking'],
            'storageKey'     => $state['storageKey'],
            's3Url'          => $state['archiveUrl'],
            'smallUrl'       => $state['smallUrl'],
            // #7 item 3: dimensions and mime used to exist only on the webhook,
            // so media:sync alone could never populate media.width — 0 of
            // musdig's 26,344 rows had one.
            'mime'           => $state['mime'],
            'width'          => $state['width'],
            'height'         => $state['height'],
            'context'        => $state['context'],
            'iiifManifestId' => $asset->iiifManifestEntity?->id,
            'iiifManifest'   => $asset->iiifManifestEntity?->manifestUrl ?? ($asset->sourceMeta['iiif_manifest'] ?? null),
            'iiifBase'       => $asset->iiifManifestEntity?->imageBase ?? ($asset->sourceMeta['iiif_base'] ?? null),
            'iiifThumb'      => $asset->iiifManifestEntity?->thumbnailUrl ?? ($asset->sourceMeta['iiif_thumbnail_url'] ?? null),
            'clients'        => $asset->clients,
            'dispatched'     => $dispatched ? 'yes' : 'no',
        ];
    }

    /**
     * The `asset.analyzed` body POSTed to context['callback_url'].
     *
     * `event` is redundant with the `Webhook-Event` header the framework now sends (and which
     * the receiver authenticates against), but stays in the body so a captured payload is still
     * self-describing in a log or a replay file.
     *
     * @return array<string,mixed>
     */
    public function webhookPayload(Asset $asset): array
    {
        $state = $this->mediaState($asset);

        return [
            'event'       => 'asset.analyzed',
            'assetId'     => $asset->id,
            'originalUrl' => $state['originalUrl'],
            'clients'     => $asset->clients,
            'marking'     => $state['marking'],
            'mime'        => $state['mime'],
            'width'       => $state['width'],
            'height'      => $state['height'],
            // #7 item 4: the webhook sent only the public archiveUrl, so a
            // client wanting media.storage_key had to parse it back out of a
            // URL for a value mediary was holding all along.
            'storageKey'  => $state['storageKey'],
            'archiveUrl'  => $state['archiveUrl'],
            'smallUrl'    => $state['smallUrl'],
            'context'     => $state['context'],
        ];
    }

    /**
     * Queue the completion notification. Returns whether it was ACCEPTED for delivery, not
     * whether it arrived — the two are different now, and the name says which one this is.
     *
     * Delivery moved onto Messenger (`SendWebhookMessage` → `webhook.transport`). What that
     * buys, none of which the previous inline POST had:
     *
     *   - **The request leaves the transition.** mediary fired this synchronously from inside a
     *     workflow transition that must still commit, so it swallowed every error and a slow
     *     subscriber slowed mediary's own pipeline down. Now the transition dispatches and
     *     returns.
     *   - **Failures retry.** A 5xx or a timeout is retried with backoff by the worker; a 4xx
     *     stops immediately and lands in the failure transport. See
     *     {@see \Survos\Kit\Webhook\VerifyingWebhookTransport} — Symfony's stock transport
     *     ignores the response entirely, which is how "fired" used to mean "we called
     *     request() and didn't look".
     *   - **It is signed.** `webhook.signer` adds `Webhook-Signature: sha256=…` over
     *     name + id + body. Until this change the callback was completely unauthenticated:
     *     anyone who could reach a subscriber's endpoint could rewrite its media rows.
     *
     * `.wip` proxying did not disappear, it moved: `survos.webhook.http_client` decorates
     * `http_client` with fetch-bundle's WipProxyHttpClient, which now decides per request URL
     * rather than per scoped client. See config/services.yaml.
     */
    public function notify(Asset $asset, string $callbackUrl): bool
    {
        if (($this->secret ?? '') === '') {
            // Fail closed and loudly. Subscriber's constructor would throw on an empty secret
            // anyway; catching it here says WHICH thing is misconfigured.
            $this->logger->error('MEDIARY_WEBHOOK_SECRET is not set — refusing to send unsigned webhook for asset {id}', [
                'id' => $asset->id,
            ]);

            return false;
        }

        $this->bus->dispatch(new SendWebhookMessage(
            new Subscriber($callbackUrl, $this->secret),
            new RemoteEvent(self::EVENT_ASSET_ANALYZED, $asset->id, $this->webhookPayload($asset)),
        ));

        return true;
    }

    /**
     * Rewrite stored callback URLs after a subscriber moves its endpoint.
     *
     * Needed because the callback URL is recorded per asset, in `context['callback_url']`, at
     * REGISTRATION time. When the receiving endpoint moved from the unauthenticated
     * `/media/callback` to `/webhook/mediary`, every already-registered asset kept pointing at
     * the old path — and there are ~608k of them. Re-registering an asset overwrites it
     * (last-writer-wins), so this only matters for assets nobody re-syncs, which is most of
     * them.
     *
     * A plain UPDATE rather than anything clever: the column is jsonb, both values are known
     * strings, and the operation is idempotent.
     */
    #[AsCommand('webhook:migrate-callback-urls', 'Rewrite stored callback_url values after a subscriber moves its endpoint')]
    public function migrateCallbackUrls(
        SymfonyStyle $io,
        Connection $connection,
        #[Option('Path to replace, e.g. /media/callback')]
        string $from = '/media/callback',
        #[Option('Path to replace it with')]
        string $to = '/webhook/mediary',
        #[Option('Report what would change without writing')]
        bool $dryRun = false,
    ): int {
        // Host is deliberately untouched: subscribers keep their own hostnames, and only the
        // path moved. Matching on the path alone migrates every subscriber in one pass.
        $count = (int) $connection->fetchOne(
            "SELECT count(*) FROM asset WHERE context->>'callback_url' LIKE :pattern",
            ['pattern' => '%' . $from],
        );

        if ($count === 0) {
            $io->success(\sprintf('No assets have a callback_url ending in "%s".', $from));

            return Command::SUCCESS;
        }

        $io->writeln(\sprintf('Assets pointing at <comment>%s</comment>: <info>%d</info>', $from, $count));

        if ($dryRun) {
            $io->note('Dry run — nothing written.');

            return Command::SUCCESS;
        }

        // Two sets of casts, both load-bearing:
        //
        //   ::jsonb / ::json   `asset.context` is a `json` column, not `jsonb`, and jsonb_set
        //                      has no json overload. Round-tripping through jsonb is the only
        //                      way to edit one key; it also normalises whitespace and key
        //                      order, which is harmless for a machine-written blob.
        //   ::text             Postgres infers a placeholder's type from context, and inside
        //                      replace()/to_jsonb() there is none — without these it fails
        //                      with "could not determine data type of parameter".
        $updated = $connection->executeStatement(
            "UPDATE asset
                SET context = jsonb_set(context::jsonb, '{callback_url}',
                        to_jsonb(replace(context->>'callback_url', :from ::text, :to ::text)))::json
              WHERE context->>'callback_url' LIKE :pattern ::text",
            ['from' => $from, 'to' => $to, 'pattern' => '%' . $from],
        );

        $io->success(\sprintf('Rewrote %d callback URL(s): %s → %s', $updated, $from, $to));

        return Command::SUCCESS;
    }

    /**
     * The facts. Both payloads are a renaming of this and nothing else.
     *
     * @return array<string,mixed>
     */
    private function mediaState(Asset $asset): array
    {
        return [
            'originalUrl' => $asset->originalUrl,
            'marking'     => $asset->marking,
            'storageKey'  => $asset->storageKey,
            'archiveUrl'  => $asset->archiveUrl,
            'smallUrl'    => $asset->smallUrl,
            'mime'        => $asset->mime,
            'width'       => $asset->width,
            'height'      => $asset->height,
            'context'     => $this->clientContext($asset),
        ];
    }

    /** @return array<string,mixed> */
    private function clientContext(Asset $asset): array
    {
        $context = $asset->context ?? [];
        $out     = [];
        foreach (self::CONTEXT_KEYS as $key) {
            $out[$key] = $context[$key] ?? null;
        }

        return $out;
    }
}
