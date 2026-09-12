<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Ai\AssetAiBatchApplier;
use App\Ai\AssetAiBatchSubmitter;
use App\Entity\Asset;
use App\Service\ClaimSearchSync;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RawClaim;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Message\ApplyBatchResultsMessage;
use Tacman\AiBatch\Service\OpenAiBatchClient;

/**
 * Asset-task batches (AssetAiBatchSubmitter: any BatchableTaskInterface task, any provider) go to
 * AssetAiBatchApplier. What follows is the older observe-only path (media:batch-observe).
 *
 * Apply a completed observe batch into the shared claims store. Each result's custom_id is the Asset
 * id (xxh3 of the image url); its JSON content is mapped to `observe:*` RawClaims and recorded via
 * ClaimIngestor keyed (scope=dataset, subjectType=Asset, subjectId=asset id) — the same record-centric
 * keying the synchronous observe uses, so consumers (md's claims:fetch → folio) pick them up unchanged.
 *
 * Raw output is read from the durable S3 copy (savedResultPath); if that's gone it is re-downloaded
 * from OpenAI while the file still exists (~a month). Idempotent: ClaimIngestor replaces per
 * (subject, source, scope) and an applied batch is skipped.
 */
#[AsMessageHandler]
final class ApplyBatchResultsMessageHandler
{
    private const SOURCE = 'observe-batch';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OpenAiBatchClient $client,
        private readonly ClaimIngestor $ingestor,
        #[Autowire(service: 'archive.storage')]
        private readonly FilesystemOperator $storage,
        private readonly LoggerInterface $logger,
        private readonly ClaimSearchSync $claimSearchSync,
        private readonly AssetAiBatchApplier $assetTaskApplier,
    ) {
    }

    public function __invoke(ApplyBatchResultsMessage $message): void
    {
        $batch = $this->em->getRepository(AiBatch::class)->find($message->aiBatchId);
        if (!$batch instanceof AiBatch || $batch->status === 'applied') {
            return;
        }
        if (($batch->meta['kind'] ?? null) === AssetAiBatchSubmitter::KIND) {
            $this->assetTaskApplier->apply($batch);

            return;
        }

        $content = $this->rawResults($batch);
        if ($content === null) {
            $this->logger->error('observe-batch {id}: no results available.', ['id' => $batch->id]);
            return;
        }

        $assets = 0;
        $claimsTotal = 0;
        $assetIds = [];
        foreach (explode("\n", trim($content)) as $line) {
            if ($line === '') {
                continue;
            }
            $rec = json_decode($line, true);
            $assetId = is_array($rec) ? ($rec['custom_id'] ?? null) : null;
            $body = $rec['response']['body']['choices'][0]['message']['content'] ?? null;
            if (!is_string($assetId) || !is_string($body)) {
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                continue;
            }

            $claims = $this->toClaims($data);
            if ($claims === []) {
                continue;
            }
            $this->ingestor->record($batch->datasetKey, Asset::class, $assetId, self::SOURCE, $claims);
            $assets++;
            $claimsTotal += \count($claims);
            $assetIds[] = $assetId;
        }
        // ClaimIngestor uses its own (survos_claims) EM and does NOT auto-flush — must flush via it,
        // not our default EM, or the claims silently never commit.
        $this->ingestor->flush();

        // Keep the FTS columns fresh so these assets are findable via /media/search
        // immediately, not just after the next backfill run (app:asset:sync-search-claims).
        if ($assetIds !== []) {
            try {
                $touched = $this->em->getRepository(Asset::class)->findBy(['id' => array_unique($assetIds)]);
                $this->claimSearchSync->sync($touched);
            } catch (\Throwable $e) {
                $this->logger->warning('observe-batch {id}: failed to sync search columns: {err}', ['id' => $batch->id, 'err' => $e->getMessage()]);
            }
        }

        $batch->appliedCount = $claimsTotal;
        $batch->status = 'applied';
        $this->em->flush();

        $this->logger->info('observe-batch {id} applied: {c} claim(s) across {a} asset(s)', ['id' => $batch->id, 'c' => $claimsTotal, 'a' => $assets]);
    }

    /**
     * Map the model's observe JSON to observe:* RawClaims — the same predicates the synchronous
     * observe pipeline emits, so downstream keying/consumers are unchanged.
     *
     * @param array<string,mixed> $data
     * @return list<RawClaim>
     */
    private function toClaims(array $data): array
    {
        $claims = [];
        foreach (['caption' => 'observe:caption', 'description' => 'observe:description', 'classification' => 'observe:classification'] as $key => $predicate) {
            $v = $data[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $claims[] = new RawClaim($predicate, trim($v));
            }
        }
        foreach ((array) ($data['tags'] ?? []) as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $claims[] = new RawClaim('observe:tag', trim($tag));
            }
        }

        return $claims;
    }

    private function rawResults(AiBatch $batch): ?string
    {
        if ($batch->savedResultPath !== null) {
            try {
                if ($this->storage->fileExists($batch->savedResultPath)) {
                    return $this->storage->read($batch->savedResultPath);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('observe-batch {id}: S3 read failed ({err}); re-downloading.', ['id' => $batch->id, 'err' => $e->getMessage()]);
            }
        }
        if ($batch->providerBatchId === null) {
            return null;
        }
        try {
            $job = $this->client->checkBatch($batch->providerBatchId);
            if (!$job->isComplete() || $job->outputFileId === null) {
                return null;
            }
            $lines = [];
            foreach ($this->client->fetchResults($job) as $r) {
                $lines[] = json_encode($r->raw, JSON_UNESCAPED_UNICODE);
            }

            return implode("\n", $lines) . "\n";
        } catch (\Throwable $e) {
            $this->logger->error('observe-batch {id}: re-download failed: {err}', ['id' => $batch->id, 'err' => $e->getMessage()]);
            return null;
        }
    }
}
