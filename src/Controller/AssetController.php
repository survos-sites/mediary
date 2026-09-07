<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AssetAiExecutor;
use App\Ai\AssetAiTask;
use App\Ai\AssetAiTaskRunner;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use App\Service\AssetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/media', name: 'asset_')]
final class AssetController extends AbstractController
{
    /** Vision tasks that should always run async — they take 30-60s */
    private const ASYNC_TASKS = ['enrich_from_thumbnail', 'context_description', 'extract_metadata'];

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AssetAiTaskRunner $runner,
        private readonly AssetRegistry $assetRegistry,
        private readonly EntityManagerInterface $em,
        private readonly TaskRegistry $taskRegistry,
        private readonly AsyncQueueLocator $asyncQueueLocator,
        private readonly \Symfony\Component\Messenger\MessageBusInterface $bus,
        private readonly AssetAiExecutor $executor,
        private readonly \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
    ) {
    }

    private function taskMeta(): array
    {
        return $this->taskRegistry->allMeta();
    }

    /** Browse grid — most recent first, simple pagination. */
    #[Route('', name: 'browse')]
    public function browse(Request $request): Response
    {
        $page    = max(1, (int) $request->query->get('page', 1));
        $limit   = 24;
        $offset  = ($page - 1) * $limit;

        // Optional filters
        $type    = $request->query->get('type');
        $marking = $request->query->get('marking');
        $search  = $request->query->get('q');

        $qb = $this->assetRepository->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($type) {
            $qb->andWhere('a.aiDocumentType = :type')->setParameter('type', $type);
        }
        if ($marking) {
            $qb->andWhere('a.marking = :marking')->setParameter('marking', $marking);
        }
        if ($search) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'a.aiTitle LIKE :q',
                    'a.aiDescription LIKE :q',
                    'a.originalUrl LIKE :q',
                )
            )->setParameter('q', '%' . $search . '%');
        }

        $assets = $qb->getQuery()->getResult();
        $total  = (int) $this->assetRepository->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()->getSingleScalarResult();

        // Facet counts for the sidebar
        $types = $this->em->createQuery(
            'SELECT a.aiDocumentType as type, COUNT(a.id) as cnt
             FROM App\Entity\Asset a
             WHERE a.aiDocumentType IS NOT NULL
             GROUP BY a.aiDocumentType
             ORDER BY cnt DESC'
        )->getResult();

        return $this->render('asset/browse.html.twig', [
            'assets'     => $assets,
            'total'      => $total,
            'page'       => $page,
            'pages'      => (int) ceil($total / $limit),
            'limit'      => $limit,
            'types'      => $types,
            'filter'     => compact('type', 'marking', 'search'),
            'markings'   => ['new', 'archived', 'informed', 'triaged', 'analyzed', 'complete', 'ai_ready', 'failed'],
        ]);
    }

    /** API Grid view. */
    #[Route('/grid', name: 'browse_grid')]
    public function browseGrid(Request $request): Response
    {
        return $this->render('asset/browse-grid.html.twig');
    }

    #[Route('/search', name: 'search')]
    public function search(): Response
    {
        return $this->render('asset/search.html.twig');
    }

    /** Detail page for a single asset with AI task runner UI. */
    #[Route('/{id}', name: 'show', requirements: ['id' => '[0-9a-f]{16}'], options: ['expose' => true])]
    public function show(Asset $asset): Response
    {
        $computedArchiveUrl = $asset->storageKey ? $this->assetRegistry->s3Url($asset) : null;
        $computedPreview = $this->assetRegistry->imgProxyDebug($asset, 'display');

        // Index completed results for template convenience
        $completedMap = [];
        foreach ($asset->aiCompleted as $entry) {
            $completedMap[$entry['task']] = $entry;
        }

        return $this->render('asset/show.html.twig', [
            'asset'        => $asset,
            'tasks'        => array_keys($this->taskRegistry->getTaskMap()),
            'taskMeta'     => $this->taskMeta(),
            'completedMap' => $completedMap,
            'claimSubjectType' => Asset::class,
            'claimSubjectId' => $asset->id,
            'computedArchiveUrl' => $computedArchiveUrl,
            'computedPreviewUrl' => $computedPreview['url'],
            'computedPreviewSource' => $computedPreview['source'],
            'computedPreviewSourceUrl' => $computedPreview['source_url'],
            'pipelines'    => [
                'quick' => array_map(fn(AssetAiTask $t) => $t->value, AssetAiTask::quickScanPipeline()),
                'full'  => array_map(fn(AssetAiTask $t) => $t->value, AssetAiTask::fullEnrichmentPipeline()),
            ],
        ]);
    }

    /**
     * HTMX/JSON endpoint: run a single named task and return a result fragment.
     * POST /assets/{id}/task/{taskName}
     */
    #[Route('/{id}/task/{taskName}', name: 'run_task', methods: ['POST', 'GET'])]
    public function runTask(Asset $asset, string $taskName, Request $request): Response
    {
        if (!$this->taskRegistry->has($taskName)) {
            if ($request->isXmlHttpRequest() || $request->headers->get('HX-Request')) {
                return new JsonResponse(['error' => "Unknown task: {$taskName}"], 400);
            }
            $this->addFlash('danger', "Unknown task: {$taskName}");
            return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
        }

        $this->applyTaskOverrideFromRequest($asset, $taskName, $request);

        // Slow vision tasks → dispatch async so HTTP doesn't idle-timeout
        $forceAsync = $request->query->getBoolean('async', false);
        $isSlowTask = in_array($taskName, self::ASYNC_TASKS, true);

        if ($forceAsync || $isSlowTask) {
            // Dispatch via workflow transition — picked up by Messenger worker
            $this->runner->enqueue($asset, [$taskName]);
            $this->em->flush();

            if ($request->headers->get('HX-Request')) {
                // Return a "queued" log entry — HTMX will poll for the real result
                $entry = ['task' => $taskName, 'at' => date('c'), 'result' => ['queued' => true, 'reason' => 'Running async — refresh in a few seconds']];
                $html  = $this->renderView('asset/_task_result_log.html.twig', ['entry' => $entry]);
                return new Response($html, 202, [
                    'HX-Trigger' => json_encode(['taskQueued' => $taskName]),
                ]);
            }
            $this->addFlash('info', "Task {$taskName} queued — check back in a few seconds.");
            return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
        }

        try {
            $ran = $this->runner->runNamed($asset, $taskName);
        } catch (\Throwable $e) {
            if ($request->isXmlHttpRequest() || $request->headers->get('HX-Request')) {
                return new JsonResponse(['error' => $e->getMessage()], 500);
            }
            $this->addFlash('danger', "Task failed: {$e->getMessage()}");
            return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
        }

        // Find the result we just recorded
        $result = null;
        foreach (array_reverse($asset->aiCompleted) as $entry) {
            if ($entry['task'] === $taskName) {
                $result = $entry;
                break;
            }
        }

        // HTMX: return a log entry fragment (prepended into #task-log) plus an
        // out-of-band swap that refreshes the task card button to its post-run state.
        if ($request->headers->get('HX-Request')) {
            $entry = $result ?? ['task' => $taskName, 'at' => date('Y-m-d H:i:s'), 'result' => ['failed' => true, 'error' => 'No result recorded']];
            $isDone     = true;
            $hasFailed  = isset($entry['result']['failed']);
            $completedMap = [];
            foreach ($asset->aiCompleted as $e) {
                $completedMap[$e['task']] = $e;
            }
            $taskMeta   = $this->taskMeta();
            $meta       = $taskMeta[$taskName] ?? [];
            $popContent = $this->buildPopoverContent($meta, $entry);

            // The log entry fragment goes to #task-log (hx-target on the form).
            // The OOB fragment updates the card col so the button reflects the new state.
            $logHtml  = $this->renderView('asset/_task_result_log.html.twig', ['entry' => $entry]);
            $cardHtml = $this->renderView('asset/_task_card.html.twig', [
                'task'       => $taskName,
                'isDone'     => $isDone,
                'hasFailed'  => $hasFailed,
                'entry'      => $entry,
                'meta'       => $meta,
                'popContent' => $popContent,
                'assetId'    => $asset->id,
            ]);

            // Wrap card in an OOB swap targeting the stable col ID.
            $oob = sprintf(
                '<div id="task-card-col-%s" hx-swap-oob="innerHTML:#task-card-col-%s">%s</div>',
                htmlspecialchars($taskName, ENT_QUOTES),
                htmlspecialchars($taskName, ENT_QUOTES),
                $cardHtml,
            );

            return new Response($logHtml . $oob, 200, ['Content-Type' => 'text/html']);
        }

        $this->addFlash('success', "Task {$taskName} completed.");
        return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
    }

    private function applyTaskOverrideFromRequest(Asset $asset, string $taskName, Request $request): void
    {
        $image = strtolower((string) $request->query->get('image', ''));
        $model = trim((string) $request->query->get('model', ''));

        $override = [];
        if (in_array($image, ['full', 'thumbnail', 'thumb', 'small'], true)) {
            $override['image_source_preference'] = $image === 'full' ? 'full' : 'thumbnail';
        }
        if ($model !== '') {
            $override['model'] = $model;
        }

        if ($override === []) {
            return;
        }

        $asset->context ??= [];
        $asset->context['ai_task_overrides'] ??= [];
        if (!is_array($asset->context['ai_task_overrides'])) {
            $asset->context['ai_task_overrides'] = [];
        }

        $asset->context['ai_task_overrides'][$taskName] = array_merge(
            $override,
            ['requested_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)]
        );
        $this->em->flush();
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed>|null $entry
     */
    private function buildPopoverContent(array $meta, ?array $entry = null): string
    {
        $lines = [
            'agent: ' . ($meta['agent'] ?? '?'),
            'model: ' . ($meta['model'] ?? '—'),
            'platform: ' . ($meta['platform'] ?? '—'),
        ];

        $debug = $entry['result']['_debug'] ?? null;
        if (is_array($debug)) {
            $imageUrl = $debug['image_url'] ?? null;
            $imageSource = $debug['image_source'] ?? null;
            if (is_string($imageUrl) && $imageUrl !== '') {
                $lines[] = '';
                if (is_string($imageSource) && $imageSource !== '') {
                    $lines[] = 'image_source: ' . $imageSource;
                }
                $lines[] = 'image_url: ' . $imageUrl;
            }

            $prompt = $debug['prompt']['combined'] ?? null;
            if (is_string($prompt) && $prompt !== '') {
                $lines[] = '';
                $lines[] = 'prompt:';
                $lines[] = $prompt;
                return implode("\n", $lines);
            }
        }

        $systemPrompt = $meta['system_prompt'] ?? 'no prompt info';
        $lines[] = '';
        $lines[] = mb_substr((string) $systemPrompt, 0, 600);

        return implode("\n", $lines);
    }

    /**
     * Enqueue a pipeline and redirect back.
     * POST /assets/{id}/pipeline/{name}
     */
    #[Route('/{id}/pipeline/{name}', name: 'enqueue_pipeline', methods: ['GET', 'POST'])]
    public function enqueuePipeline(Asset $asset, string $name): Response
    {
        $tasks = match ($name) {
            'quick' => AssetAiTask::quickScanPipeline(),
            'full'  => AssetAiTask::fullEnrichmentPipeline(),
            default => null,
        };
        // $tasks is array<AssetAiTask> — runner->enqueue() accepts enum or string

        if ($tasks === null) {
            $this->addFlash('danger', "Unknown pipeline: {$name}");
            return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
        }

        $asset->aiQueue = [];
        $this->runner->enqueue($asset, $tasks);
        $this->addFlash('success', count($tasks) . " tasks enqueued ({$name} pipeline).");

        return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
    }

    /**
     * Toggle the aiLocked flag.
     * POST /assets/{id}/lock
     */
    #[Route('/{id}/lock', name: 'toggle_lock', methods: ['POST'])]
    public function toggleLock(Asset $asset): Response
    {
        $asset->aiLocked = !$asset->aiLocked;
        $this->em->flush();
        $state = $asset->aiLocked ? 'locked' : 'unlocked';
        $this->addFlash('info', "Asset {$state}.");
        return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
    }
}
