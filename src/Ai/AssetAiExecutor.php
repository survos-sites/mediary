<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Asset;
use App\Entity\MediaRecord;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use App\Service\ClaimSearchSync;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\AiWorkflowBundle\Task\TaskResult;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use App\Service\SidecarService;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;

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
        private readonly ?ImgproxyUrlBuilder $imageUrls = null,
        private readonly ?ClaimRepository $claims = null,
    ) {
    }

    /** dcterms predicates of the producer's @import claims -> the task-context keys AbstractPromptTask::knownFacts() reads. */
    private const array FACT_KEYS = [
        'dcterms:title' => 'title',
        'dcterms:abstract' => 'caption',
        'dcterms:description' => 'description',
        'dcterms:date' => 'date',
        'dcterms:creator' => 'creator',
        'dcterms:spatial' => 'place',
        'dcterms:isPartOf' => 'collection',
    ];

    /**
     * The item's catalogue facts, from the claims the producer sent with the batch. Those are
     * stored as @import claims on the asset's MediaRecord (BatchController::sourceClaimItem), and
     * the ingestor replaces the previous run, so this is always the producer's latest. Without
     * this, observe and fortepan_curation_score saw no title, date, place or creator at all -- the
     * curation score called newspaper photographs "amateur".
     *
     * @return array<string, string|list<string>>
     */
    private function knownFacts(Asset $asset): array
    {
        if ($this->claims === null || $asset->mediaRecord === null) {
            return [];
        }
        $facts = [];
        foreach ($this->claims->findForSubjectAndSource(MediaRecord::class, $asset->mediaRecord->id, '@import', $asset->dataset) as $claim) {
            $key = self::FACT_KEYS[$claim->predicate] ?? null;
            if ($key !== null && is_scalar($claim->value) && trim((string) $claim->value) !== '') {
                $facts[$key][] = (string) $claim->value;
            }
        }

        return array_map(static fn (array $v): string|array => count($v) === 1 ? $v[0] : array_values(array_unique($v)), $facts);
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
            // LOUD. An unknown task name is a configuration bug in the caller, not a runtime
            // condition, and it used to return ok:false in silence -- no log, no exception, no
            // entry in the failure transport. A client requesting a task this server does not
            // have got no answer and no reason, and the absence of output was indistinguishable
            // from "no AI was requested for this asset". Found exactly that way: a client had
            // been asking for names that never existed here, for as long as anyone could tell.
            //
            // Still a return rather than a throw: one bad task name in an aiQueue must not kill
            // the sibling tasks on the same asset. The error log plus the known-task list is what
            // makes it findable -- the caller almost always has a typo or a stale name, and
            // seeing what IS registered is the fastest route to the fix.
            $this->logger->error(
                'AssetAiExecutor: no handler registered for AI task "{task}" (asset {asset}); known tasks: {known}',
                [
                    'task' => $task,
                    // Property hook, not a getter -- Asset has no getId(); calling one here
                    // fatals on the very path that exists to report a problem.
                    'asset' => $asset->id,
                    'known' => implode(', ', array_keys($this->registry->getTaskMap())),
                ],
            );

            return ['ok' => false, 'cached' => false, 'response' => [], 'reason' => 'task handler not found'];
        }

        $subject = new AssetSubject($asset, $context, $this->imageUrls, $this->knownFacts($asset));
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
        // PDF Tools caches each page operation. Re-entering that task also records
        // its claims after an interrupted DB write; a sidecar hit alone cannot do so.
        if (!$force && $task !== PdfOcrTask::TASK) {
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

        return ['ok' => true, 'cached' => false, 'response' => $this->record($asset, $task, $subject, $taskObj->run($subject))];
    }

    /**
     * Persist one task result for an asset: the S3 sidecar (cache-aside), the claims, and the
     * search columns. The sync path above and the provider-batch path (AssetAiBatchApplier) both
     * end here, so an asset's claims do not depend on which way its task ran.
     *
     * @return array<string,mixed> the run's response (what aiCompleted records)
     */
    public function record(Asset $asset, string $task, AssetSubject $subject, TaskResult $result): array
    {
        $response = (array) ($result->meta?->response ?? []);

        if ($this->sidecar->isAvailable()) {
            // Tolerant, like the read in run(). The paid call has already happened and the
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
                throw $e;
            }
        }

        return $response;
    }
}
