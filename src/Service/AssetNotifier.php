<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface     $logger,
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
     * POST the completion notification. Never throws: a client being down is
     * the client's problem, and mediary fires this from inside a workflow
     * transition that must still commit. ReplayWebhooksCommand exists to
     * redeliver whatever failed here.
     */
    public function fire(Asset $asset, string $callbackUrl): bool
    {
        try {
            $options = [
                'json'    => $this->webhookPayload($asset),
                'timeout' => 10,
            ];
            // `.wip` isn't real DNS — it only resolves through Symfony CLI's
            // local proxy. Without this a local client's callback silently
            // fails every time, which is precisely the condition
            // ReplayWebhooksCommand was written to clean up after.
            if (str_contains($callbackUrl, '.wip')) {
                $options['proxy'] = 'http://127.0.0.1:7080';
            }

            $response = $this->httpClient->request('POST', $callbackUrl, $options);
            $status   = $response->getStatusCode();

            $this->logger->info('Webhook fired to {url} for asset {id} → HTTP {status}', [
                'url'    => $callbackUrl,
                'id'     => $asset->id,
                'status' => $status,
            ]);

            return $status < 400;
        } catch (\Throwable $e) {
            $this->logger->error('Webhook failed for {id}: {err}', [
                'id'  => $asset->id,
                'err' => $e->getMessage(),
            ]);

            return false;
        }
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
