<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Asset;
use App\Service\AssetPresigner;
use App\Service\SidecarService;
use App\Workflow\AssetFlow;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\AiWorkflowBundle\Task\BatchableTaskInterface;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\StateBundle\Attribute\AsBatchTransitionListener;
use Survos\StateBundle\Event\BatchTransitionEvent;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Service\BatchClients;

/**
 * The provider-batch half of `ai_task` (docs/ai-batching.md).
 *
 * ai_task is declared #[Transition(batch: N)], so state-bundle hands this listener up to N
 * assets at once. For each, the next task in aiQueue is looked at:
 *  - batchable (BatchableTaskInterface, a URL the provider can fetch, no force/sync override,
 *    nothing cached): its request joins a provider job, one job per (provider, endpoint, model,
 *    task). The asset is marked context['ai_batch'] = {task, batch, ...}; then the transition is
 *    applied with the `batched` flag and AssetWorkflow::onAiTask takes the task off the queue and
 *    locks the asset instead of running it. AssetAiBatchApplier unlocks it when results land.
 *  - anything else is released: re-dispatched as an ordinary ai_task and run synchronously, one
 *    message per asset, exactly as before batching existed.
 *
 * If the provider refuses a job (4xx), its assets are released to sync. If a submission throws
 * anything else, state-bundle nacks the whole group and Messenger retries it; groups already
 * submitted keep their marker, so the retry doesn't pay for them twice.
 */
final class AssetAiBatchSubmitter
{
    /** AiBatch.meta['kind'] for jobs this class submits. */
    public const string KIND = 'asset_ai_task';

    /** Asset.context key: the provider batch an asset's current task is waiting on. */
    public const string PENDING = 'ai_batch';

    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly BatchClients $clients,
        private readonly SidecarService $sidecar,
        private readonly AssetPresigner $presigner,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsBatchTransitionListener(AssetFlow::WORKFLOW_NAME, AssetFlow::TRANSITION_AI_TASK)]
    public function onBatch(BatchTransitionEvent $event): void
    {
        /** @var array<string, array{provider: string, task: string, scope: ?string, items: list<array{0: Asset, 1: BatchRequest}>}> $groups */
        $groups = [];
        foreach ($event->subjects as $asset) {
            \assert($asset instanceof Asset);
            $task = $asset->aiQueue[0] ?? null;
            $pending = $asset->context[self::PENDING] ?? null;
            if (is_array($pending) && is_string($task) && ($pending['task'] ?? null) === $task) {
                continue; // a redelivery of an asset already in a provider job: just apply
            }

            $request = is_string($task) ? $this->requestFor($asset, $task) : null;
            if ($request === null) {
                $event->release($asset);
                continue;
            }
            [$provider, $batchRequest, $scope] = $request;
            $key = implode('|', [$provider, $batchRequest->endpoint, $batchRequest->model, $task]);
            $groups[$key] ??= ['provider' => $provider, 'task' => $task, 'scope' => $scope, 'items' => []];
            $groups[$key]['items'][] = [$asset, $batchRequest];
        }

        foreach ($groups as $group) {
            try {
                $this->submit($group['provider'], $group['task'], $group['scope'], $group['items']);
            } catch (ClientExceptionInterface $e) {
                // The provider refused the job (4xx: no batch access -- Mistral's 402 without
                // pay-as-you-go -- or a request it rejects). Retrying cannot help; the sync path
                // still gets the work done, at full price. Anything else (network, 5xx) throws on,
                // and state-bundle retries the group.
                $this->logger->error('ai-batch: {provider} refused {n} × {task}, running them sync: {err}', [
                    'provider' => $group['provider'], 'n' => \count($group['items']), 'task' => $group['task'], 'err' => $e->getMessage(),
                ]);
                foreach ($group['items'] as [$asset]) {
                    $event->release($asset);
                }
            }
        }
    }

    /**
     * @return array{0: string, 1: BatchRequest, 2: ?string}|null provider, request, claims scope;
     *                                                          null when the task must run sync
     */
    private function requestFor(Asset $asset, string $task): ?array
    {
        $taskObj = $this->registry->get($task);
        if (!$taskObj instanceof BatchableTaskInterface || !$this->clients->has($taskObj->batchProvider())) {
            return null;
        }
        $override = $asset->context['ai_task_overrides'][$task] ?? [];
        if (!is_array($override)) {
            $override = [];
        }
        if (!empty($override['force']) || !empty($override['sync'])) {
            return null; // a forced re-run or an explicit sync request: the debugging paths
        }

        // Same context runNextAiTask() gives the sync run, so both paths send the same request...
        $context = $asset->context ?? [];
        if (isset($override['model']) && is_string($override['model']) && $override['model'] !== '') {
            $context['model_hint'] = $override['model'];
        }
        // ...except for where the provider reads the bytes. A batch worker fetches hours later,
        // on its own, so point it at our archived master under a signature that expires rather
        // than at the source: mediary already downloaded these bytes, and sources are not
        // reliably fetchable by a third party (Mistral cannot fetch archive.org IIIF at all).
        // Transient -- never written back to asset.context, which would persist a signed URL.
        $presigned = $this->presigner->archiveUrl($asset);
        if ($presigned !== null) {
            $context['image_url'] = $presigned;
        }
        $subject = new AssetSubject($asset, $context);
        if (!$taskObj->supports($subject) || !$taskObj->supportsBatch($subject)) {
            return null;
        }
        // A cached sidecar answers instantly and for free on the sync path.
        try {
            if ($this->sidecar->read($asset->id, $task) !== null) {
                return null;
            }
        } catch (\Throwable) {
            // unreadable cache: a miss, as in AssetAiExecutor::run()
        }

        $request = $taskObj->batchRequest($subject);

        return [
            $taskObj->batchProvider(),
            BatchRequest::raw($asset->id, $request['endpoint'], $request['body']),
            $subject->getWorkflowScope(),
        ];
    }

    /** @param list<array{0: Asset, 1: BatchRequest}> $items */
    private function submit(string $provider, string $task, ?string $scope, array $items): void
    {
        $requests = array_map(static fn (array $i): BatchRequest => $i[1], $items);
        $job = $this->clients->get($provider)->submitBatch($requests, [
            'metadata' => ['app' => 'mediary', 'kind' => self::KIND, 'task' => $task],
        ]);

        $batch = new AiBatch();
        $batch->provider = $provider;
        $batch->task = $task;
        $batch->datasetKey = $scope;
        $batch->requestCount = \count($items);
        $batch->markSubmitted($job->id, (string) ($job->inputFileId ?? ''));
        $batch->meta = [
            'kind' => self::KIND,
            'endpoint' => $requests[0]->endpoint,
            'model' => $requests[0]->model,
            'assets' => array_map(static fn (array $i): string => $i[0]->id, $items),
        ];
        $this->em->persist($batch);
        $this->em->flush();

        $at = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        foreach ($items as [$asset]) {
            $context = $asset->context ?? [];
            $context[self::PENDING] = ['task' => $task, 'batch' => $batch->id, 'provider' => $provider, 'job' => $job->id, 'at' => $at];
            $asset->context = $context;
        }
        $this->em->flush();

        $this->logger->info('ai-batch: {n} × {task} → {provider} job {job} (AiBatch {id})', [
            'n' => \count($items), 'task' => $task, 'provider' => $provider, 'job' => $job->id, 'id' => $batch->id,
        ]);
    }
}
