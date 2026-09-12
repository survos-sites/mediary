<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Asset;
use App\Workflow\AssetWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Survos\AiWorkflowBundle\Task\BatchableTaskInterface;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Model\BatchResult;
use Tacman\AiBatch\Service\BatchClients;

/**
 * Lands a finished provider batch submitted by AssetAiBatchSubmitter. For every asset in the job:
 * the task's own parser turns the response body into a TaskResult (the same code its sync run()
 * uses), AssetAiExecutor::record() persists it (sidecar, claims, search columns -- the same code
 * as sync), and AssetWorkflow::completeBatchedTask() records the outcome, unlocks the asset and
 * moves it on (next ai_task, or ai_done → complete → the client's webhook).
 *
 * A failed line, a line missing from the output, or a whole job that failed or timed out ends the
 * asset's task as failed, exactly as a sync exception would. Nobody stays locked.
 *
 * Idempotent: an asset whose marker points at a different batch (re-run since) is left alone, and
 * the batch is marked `applied` once done.
 */
final class AssetAiBatchApplier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BatchClients $clients,
        private readonly TaskRegistry $registry,
        private readonly AssetAiExecutor $executor,
        private readonly AssetWorkflow $assetWorkflow,
        #[Autowire(service: 'archive.storage')]
        private readonly FilesystemOperator $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apply(AiBatch $batch): void
    {
        $taskObj = $this->registry->get($batch->task);
        $results = $this->results($batch);

        $done = $failed = 0;
        foreach ((array) ($batch->meta['assets'] ?? []) as $assetId) {
            $asset = $this->em->getRepository(Asset::class)->find($assetId);
            if (!$asset instanceof Asset) {
                continue;
            }
            $pending = $asset->context[AssetAiBatchSubmitter::PENDING] ?? null;
            if (!is_array($pending) || ($pending['batch'] ?? null) !== $batch->id) {
                continue; // re-run or already applied since this job was submitted
            }

            $outcome = $this->outcome($batch, $asset, $taskObj, $results[$assetId] ?? null);
            empty($outcome['failed']) ? $done++ : $failed++;
            $this->assetWorkflow->completeBatchedTask($asset, $batch->task, $outcome);
        }

        $batch->appliedCount = $done;
        $batch->status = 'applied';
        $this->em->flush();
        $this->logger->info('ai-batch {id} ({task}) applied: {done} done, {failed} failed', [
            'id' => $batch->id, 'task' => $batch->task, 'done' => $done, 'failed' => $failed,
        ]);
    }

    /** @return array<string, mixed> what aiCompleted records */
    private function outcome(AiBatch $batch, Asset $asset, mixed $taskObj, ?BatchResult $result): array
    {
        if (!$taskObj instanceof BatchableTaskInterface) {
            return ['failed' => true, 'error' => sprintf('task "%s" is not batchable here', $batch->task), 'batch' => $batch->id];
        }
        if ($result === null || !$result->success || $result->body === null) {
            return ['failed' => true, 'error' => $result?->error ?? sprintf('no result in %s job %s (%s)', $batch->provider, $batch->providerBatchId, $batch->status), 'batch' => $batch->id];
        }

        try {
            $subject = new AssetSubject($asset, $asset->context ?? []);
            $response = $this->executor->record($asset, $batch->task, $subject, $taskObj->batchResult($subject, $result->body));

            return ['cached' => false, 'response' => $response, 'batch' => $batch->id];
        } catch (\Throwable $e) {
            $this->logger->error('ai-batch {id}: {task} result for asset {asset} failed: {err}', [
                'id' => $batch->id, 'task' => $batch->task, 'asset' => $asset->id, 'err' => $e->getMessage(),
            ]);

            return ['failed' => true, 'error' => $e->getMessage(), 'batch' => $batch->id];
        }
    }

    /**
     * Results by custom_id (= asset id): from the durable S3 copy the poller archived, else
     * straight from the provider while its files still exist.
     *
     * @return array<string, BatchResult>
     */
    private function results(AiBatch $batch): array
    {
        $lines = [];
        if ($batch->savedResultPath !== null) {
            try {
                if ($this->storage->fileExists($batch->savedResultPath)) {
                    foreach (explode("\n", $this->storage->read($batch->savedResultPath)) as $line) {
                        if (trim($line) !== '' && is_array($row = json_decode($line, true))) {
                            $lines[] = BatchResult::fromProviderLine($batch->provider, $row);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('ai-batch {id}: archived results unreadable ({err}); asking the provider.', ['id' => $batch->id, 'err' => $e->getMessage()]);
                $lines = [];
            }
        }
        if ($lines === [] && $batch->providerBatchId !== null) {
            try {
                $client = $this->clients->get($batch->provider);
                $job = $client->checkBatch($batch->providerBatchId);
                if ($job->isTerminal()) {
                    foreach ($client->fetchResults($job) as $result) {
                        $lines[] = $result;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('ai-batch {id}: results unavailable: {err}', ['id' => $batch->id, 'err' => $e->getMessage()]);
                if ($batch->isComplete()) {
                    // The provider has the results; failing every asset over an outage would throw
                    // them away. Let Messenger retry the apply.
                    throw new \RuntimeException(sprintf('ai-batch %d: completed but results unavailable: %s', $batch->id, $e->getMessage()), 0, $e);
                }
            }
        }

        $byId = [];
        foreach ($lines as $result) {
            // A success wins over an error line for the same id (a retried line).
            if (!isset($byId[$result->customId]) || $result->success) {
                $byId[$result->customId] = $result;
            }
        }

        return $byId;
    }
}
