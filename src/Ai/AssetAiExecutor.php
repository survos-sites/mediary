<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Asset;
use App\Service\ClaimSearchSync;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\MediaBundle\Service\SidecarService;

/**
 * Runs a single ai-workflow-bundle task against an Asset (via {@see AssetSubject})
 * and persists the result: cached on the S3 sidecar (cache-aside) and recorded as
 * claims. This is the one place that bridges Asset → ai-workflow, replacing the
 * removed ai-pipeline-bundle handler layer. Shared by AssetController (sync HTTP)
 * and AssetWorkflow (queue/transition driven).
 */
final class AssetAiExecutor
{
    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly SidecarService $sidecar,
        private readonly ?ClaimIngestor $claimIngestor = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ClaimSearchSync $claimSearchSync = null,
        private readonly ?EntityManagerInterface $em = null,
    ) {
    }

    /**
     * @param array<string,mixed> $context runtime hints (e.g. ['max_pages' => 3])
     *
     * @return array{ok:bool,cached:bool,response:array<string,mixed>,reason?:string}
     */
    public function run(Asset $asset, string $task, array $context = [], bool $force = false): array
    {
        $taskObj = $this->registry->get($task);
        if ($taskObj === null) {
            return ['ok' => false, 'cached' => false, 'response' => [], 'reason' => 'task handler not found'];
        }

        $subject = new AssetSubject($asset, $context);
        if (!$taskObj->supports($subject)) {
            return ['ok' => false, 'cached' => false, 'response' => [], 'reason' => 'not supported for this asset'];
        }

        // A cache-aside miss and a cache-aside ERROR must behave the same: run the
        // task. The sidecar is a cache (see the note below) and object storage is
        // allowed to have a bad minute — on 2026-08-17, 8 supervised workers
        // hitting Hetzner at once produced a burst of Flysystem
        // "Unable to check existence for: o/…/{id}.observe.json", and every one
        // killed its AI task outright. Nothing was wrong: the same
        // archive.storage archived 66 masters in that same window, and a HEAD on
        // those exact missing keys returns a clean 404 from the CLI.
        if (!$force) {
            try {
                if (null !== ($cached = $this->sidecar->read($asset->id, $task))) {
                    return ['ok' => true, 'cached' => true, 'response' => $cached];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('sidecar read failed for {id}/{task}, treating as a miss: {err}', [
                    'id' => $asset->id,
                    'task' => $task,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        $result = $taskObj->run($subject);
        $response = (array) ($result->meta?->response ?? []);

        if ($this->sidecar->isAvailable()) {
            // Likewise on the way out. The paid call has already happened and the
            // claims below are the durable record; losing the cache write costs a
            // re-run later, losing the claims costs the data.
            try {
                $this->sidecar->remember($asset->id, $task, static fn (): array => $response, force: true);
            } catch (\Throwable $e) {
                $this->logger->warning('sidecar write failed for {id}/{task}: {err}', [
                    'id' => $asset->id,
                    'task' => $task,
                    'err' => $e->getMessage(),
                ]);
            }
        }
        // Claims are the durable authority (DB index + claims.jsonl in the vault); the
        // S3 sidecar is just a cache-aside. ClaimIngestor persists but does not flush —
        // it leaves that to the caller so bulk runs can batch — so we flush here for the
        // per-call (sync HTTP) path. Don't swallow silently: a lost claim is real data loss.
        if ($this->claimIngestor !== null && !empty($result->claims)) {
            try {
                $this->claimIngestor->record(
                    $subject->getWorkflowScope(),
                    $subject->getWorkflowSubjectType(),
                    $subject->getWorkflowSubjectId(),
                    $task,
                    $result->claims,
                    $result->meta,
                );
                // Flush via the ingestor, which holds the claims EM (survos_claims.entity_manager).
                // Flushing the app's default EM silently never commits the claims when a separate
                // claims connection is configured — exactly mediary's setup. See ClaimIngestor::flush().
                $this->claimIngestor->flush();

                // Keep the FTS columns fresh so this asset is findable via /media/search
                // immediately, not just after the next backfill run.
                if ($this->claimSearchSync !== null && $this->em !== null) {
                    $this->claimSearchSync->sync([$asset]);
                    $this->em->flush();
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to persist claims for asset {id} task {task}: {err}', [
                    'id' => $asset->id,
                    'task' => $task,
                    'err' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        return ['ok' => true, 'cached' => false, 'response' => $response];
    }
}
