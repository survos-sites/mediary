<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Asset;
use App\Entity\MediaRecord;
use App\Service\AssetNotifier;
use App\Service\AssetRegistry;
use App\Workflow\AssetFlow;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RawClaim;
use Survos\MediaBundle\Dto\BatchPayloadDto;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class BatchController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly AssetRegistry     $assetRegistry,
        private readonly AsyncQueueLocator $asyncQueueLocator,
        private readonly ClaimIngestor     $claimIngestor,
        private readonly AssetNotifier     $assetNotifier,
    ) {
    }

    /** POST: Symfony deserializes + validates the JSON body straight into the DTO. */
    #[Route('/{client}/batch', methods: ['POST'])]
    public function post(string $client, #[MapRequestPayload] BatchPayloadDto $payload): JsonResponse
    {
        return $this->handle($client, $payload);
    }

    /** GET: debug single-URL registration via ?url=…&callback_url=… */
    #[Route('/{client}/batch', methods: ['GET'])]
    public function get(string $client, Request $request): JsonResponse
    {
        $url = $request->query->get('url');

        return $this->handle($client, new BatchPayloadDto(
            client: $client,
            urls: $url !== null ? [$url] : [],
            callbackUrl: $request->query->get('callback_url'),
        ));
    }

    private function handle(string $client, BatchPayloadDto $payload): JsonResponse
    {
        $this->logger->warning(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // sync=true: process download immediately in this request, skip async queue
        if ($payload->sync) {
            $this->asyncQueueLocator->sync = true;
        }

        $urls = $payload->cleanUrls();

        // Precompute context hints per URL once — reused for the batched asset
        // lookup below and the per-item loop, instead of recomputing per item.
        //
        // callback_url deliberately does NOT go in here. It used to, and the
        // comment claimed it was landing "in context so the workflow can fire
        // it after analysis" — but populateAsset() merges contextHints into
        // Asset::$sourceMeta, while every reader (AssetWorkflow::onCompleted,
        // ReplayWebhooksCommand) looks in Asset::$context. So the URL was
        // recorded as a piece of the record's SOURCE METADATA and the webhook
        // never fired, even for a client that sent one. See mediary#7; it is
        // applied against $asset->context explicitly below.
        $contextHintsByUrl = [];
        foreach ($urls as $url) {
            $contextHintsByUrl[$url] = $payload->itemFor($url)->toArray();
        }

        // One preload query for existing Assets + one for existing MediaRecords,
        // instead of a findOneByUrl()/findOneByRecordKey() round trip per URL.
        $assets = $this->assetRegistry->ensureAssets($contextHintsByUrl, $client);

        // Item-level source claims (title/date/place/keywords) belong to the
        // record, not the image. Ingest as @import claims keyed by the record
        // so they survive multi-image and so AI claims (own source) compare
        // against, never inherit, human metadata. Collected here and ingested
        // in one recordBatch() call below — record() opens/closes its own vault
        // JsonlWriter per call, which capped batches at ~10 URLs/sec.
        $claimItems = [];
        foreach ($urls as $url) {
            $claimItem = $this->sourceClaimItem($assets[$url], $payload->claimsFor($url));
            if ($claimItem !== null) {
                $claimItems[] = $claimItem;
            }
        }
        $this->claimIngestor->recordBatch($claimItems);

        $media = [];
        $queue = [];

        foreach ($urls as $url) {
            $asset = $assets[$url];

            // Where to publish completion. Last writer wins — unlike source
            // metadata, this is not provenance, it is a live routing decision
            // and the client asking now is the one to believe. Re-registering
            // an already-archived asset is therefore the supported way to
            // attach a callback to the ~608k assets registered before any
            // client sent one.
            //
            // KNOWN LIMIT: one slot. mediary broadcasts to many clients, so two
            // clients syncing the same URL overwrite each other and only the
            // most recent is notified. Fine today (musdig is the only client
            // with a callback), wrong the moment a second one appears — that
            // needs a per-client map, not a scalar.
            if ($payload->callbackUrl) {
                $asset->context ??= [];
                $asset->context['callback_url'] = $payload->callbackUrl;
            }

            if ($asset->marking === AssetFlow::PLACE_NEW) {
                $queue[$asset->originalUrl] = $asset;
            }
            // Same builder the asset.analyzed webhook uses, so what a client
            // learns from /batch and what it learns from the callback differ
            // only in timing — not in which fields exist. See AssetNotifier.
            $media[] = $this->assetNotifier->batchRow($asset, array_key_exists($url, $queue));
        }
        $this->assetRegistry->flush();

        // dispatch auto-download for the moment, let's focus on metadata
        foreach ($queue as $url => $asset) {
            $this->assetRegistry->dispatch($asset);
        }
        $this->assetRegistry->flush();

        // Report what we refused rather than dropping it silently. A client whose
        // source data holds identifiers instead of URLs (Smithsonian EDAN ids, say)
        // otherwise sees a short media[] and no reason for it.
        $rejected = $payload->rejectedUrls();
        if ($rejected !== []) {
            $this->logger->warning('batch[{client}]: refused {count} non-fetchable url(s), e.g. {sample}', [
                'client' => $client,
                'count' => count($rejected),
                'sample' => implode(', ', array_slice($rejected, 0, 3)),
            ]);
        }

        return new JsonResponse([
            'media' => $media,
            'rejected' => $rejected,
        ]);
    }

    /**
     * Build a recordBatch() item for one asset's item-level source metadata
     * (title/date/place/keywords), ingested as @import claims on the record
     * so they survive multi-image and so AI claims (own source) compare
     * against, never inherit, human metadata. Null when there are no claims
     * or no record — the record only exists when the caller sent a grouping
     * key (the item id as code/dcterms:identifier/media_record_key).
     *
     * @param list<array<string,mixed>> $claims list of ['predicate'=>string, 'value'=>mixed, 'confidence'?=>int, 'basis'?=>string]
     * @return array{scope: ?string, subjectType: string, subjectId: string, source: string, rawClaims: list<RawClaim>}|null
     */
    private function sourceClaimItem(Asset $asset, array $claims): ?array
    {
        if ($claims === [] || $asset->mediaRecord === null) {
            return null;
        }

        $raw = [];
        foreach ($claims as $c) {
            if (!is_array($c) || !isset($c['predicate']) || !array_key_exists('value', $c)) {
                continue;
            }
            $raw[] = new RawClaim(
                (string) $c['predicate'],
                $c['value'],
                isset($c['confidence']) ? (int) $c['confidence'] : 100,
                isset($c['basis']) ? (string) $c['basis'] : null,
            );
        }
        if ($raw === []) {
            return null;
        }

        return [
            'scope' => $asset->dataset,
            'subjectType' => MediaRecord::class,
            'subjectId' => $asset->mediaRecord->id,
            'source' => '@import',
            'rawClaims' => $raw,
        ];
    }
}
