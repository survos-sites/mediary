<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Ai\AssetAiBatchSubmitter;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Message\ApplyBatchResultsMessage;
use Tacman\AiBatch\Message\PollBatchesMessage;
use Tacman\AiBatch\Service\BatchClients;

/**
 * Scheduler-driven poll of mediary's in-flight provider batches (PollBatchesTask, every 2 min),
 * for every provider: the AiBatch row says which client to ask.
 *
 * Re-check each job's status and fold it onto its row. When a job ends, archive its raw result
 * lines to S3 (durable: providers delete batch files after a few weeks) and hand off to
 * ApplyBatchResultsMessageHandler.
 *
 * Asset-task batches (AssetAiBatchSubmitter) are handed off on ANY terminal status, a failed or
 * timed-out job included, because their assets are locked until applied. And one that has ended
 * but not landed (an apply that threw) is handed off again on every poll until it is `applied`.
 * The observe batches (media:batch-observe) keep their old rule: completed only, once.
 */
#[AsMessageHandler]
final class PollBatchesMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BatchClients $clients,
        #[Autowire(service: 'archive.storage')]
        private readonly FilesystemOperator $storage,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PollBatchesMessage $message): void
    {
        $repo = $this->em->getRepository(AiBatch::class);

        // Ended, not landed: a previous apply threw. Try again. (First, so a job that ends in
        // the loop below is handed off once per poll, not twice.)
        foreach ($repo->findBy(['status' => ['completed', 'failed']]) as $batch) {
            if (self::isAssetTask($batch)) {
                $this->handOff($batch);
            }
        }

        foreach ($repo->findBy(['status' => ['submitted', 'processing']]) as $batch) {
            $this->poll($batch);
        }
    }

    private function poll(AiBatch $batch): void
    {
        if ($batch->providerBatchId === null || !$this->clients->has($batch->provider)) {
            return;
        }
        $client = $this->clients->get($batch->provider);

        try {
            $job = $client->checkBatch($batch->providerBatchId);
        } catch (\Throwable $e) {
            $this->logger->warning('ai-batch poll failed for {provider} {id}: {err}', ['provider' => $batch->provider, 'id' => $batch->providerBatchId, 'err' => $e->getMessage()]);

            return;
        }

        $batch->applyProviderStatus($job->status, $job->completedCount, $job->failedCount, $job->outputFileId, $job->errorFileId);
        if (!$job->isTerminal()) {
            $this->em->flush();

            return;
        }

        if ($batch->savedResultPath === null && ($job->outputFileId !== null || $job->errorFileId !== null)) {
            try {
                $lines = [];
                foreach ($client->fetchResults($job) as $result) {
                    $lines[] = json_encode($result->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $key = sprintf('ai-batch/%s/%s/%s.jsonl', trim((string) ($batch->datasetKey ?? '_'), '/') ?: '_', $batch->task, $batch->providerBatchId);
                $this->storage->write($key, implode("\n", $lines) . "\n");
                $batch->savedResultPath = $key;
                $this->logger->info('ai-batch {id} {status} → {key} ({n} lines)', ['id' => $batch->providerBatchId, 'status' => $job->status, 'key' => $key, 'n' => \count($lines)]);
            } catch (\Throwable $e) {
                // Not fatal: the applier asks the provider directly when there is no S3 copy.
                $this->logger->warning('ai-batch {id}: archiving results failed: {err}', ['id' => $batch->providerBatchId, 'err' => $e->getMessage()]);
            }
        }
        $this->em->flush();

        if (self::isAssetTask($batch) || ($job->isComplete() && $batch->savedResultPath !== null)) {
            $this->handOff($batch);
        }
    }

    private function handOff(AiBatch $batch): void
    {
        try {
            $this->bus->dispatch(new ApplyBatchResultsMessage($batch->id));
        } catch (\Throwable $e) {
            // An asset-task batch is retried on the next poll; see __invoke().
            $this->logger->error('ai-batch {id}: apply failed: {err}', ['id' => $batch->id, 'err' => $e->getMessage()]);
        }
    }

    private static function isAssetTask(AiBatch $batch): bool
    {
        return ($batch->meta['kind'] ?? null) === AssetAiBatchSubmitter::KIND;
    }
}
