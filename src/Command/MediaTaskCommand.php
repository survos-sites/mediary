<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AssetAiTask;
use App\Ai\AssetAiTaskRunner;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use App\Workflow\AssetFlow as WF;
use Doctrine\ORM\EntityManagerInterface;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Run one or more AI tasks against an asset.
 *
 * The asset is identified by URL (the canonical key) or its 16-char hex ID.
 * If the URL is not in the database yet, the command explains how to add it.
 *
 * Examples:
 *
 *   # Run a specific task directly (no workflow advance, great for testing)
 *   bin/console media:task https://example.com/scan.jpg ocr
 *
 *   # Run the task that is next in the queue
 *   bin/console media:task https://example.com/scan.jpg --next
 *
 *   # Enqueue the full pipeline and run the first task
 *   bin/console media:task https://example.com/scan.jpg --pipeline=full
 *
 *   # Run all queued tasks synchronously
 *   bin/console media:task https://example.com/scan.jpg --all
 *
 *   # Just show the asset status without running anything
 *   bin/console media:task https://example.com/scan.jpg --status
 */
#[AsCommand(
    name: 'media:task',
    description: 'Run AI tasks against an asset (identified by URL or ID).',
)]
final class MediaTaskCommand
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AssetAiTaskRunner $runner,
        private readonly EntityManagerInterface $entityManager,
        #[Target(WF::WORKFLOW_NAME)]
        private readonly WorkflowInterface $assetWorkflow,
        private readonly TaskRegistry $taskRegistry,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Asset URL (http/https) or 16-char hex asset ID')]
        string $image,

        #[Argument('Task name to run directly (e.g. ocr, classify, basic_description). Omit to use --next or --all.')]
        ?string $task = null,

        #[Option('Run the next pending task from aiQueue')]
        bool $next = false,

        #[Option('Drain the full aiQueue synchronously')]
        bool $all = false,

        #[Option('Show asset status and queue without running anything')]
        bool $status = false,

        #[Option('Enqueue a pipeline before running: quick or full')]
        ?string $pipeline = null,

        #[Option('Overwrite aiQueue with the given pipeline (clears existing queue)')]
        bool $reset = false,

        #[Option('Unlock the asset (clear aiLocked) before running')]
        bool $unlock = false,

        #[Option('Pretty-print the task result as JSON')]
        bool $json = false,
        #[Option('Force re-run, bypassing the sidecar cache')]
        bool $force = false,
        #[Option('Queue the named task through the asset workflow (ai_task -- batched when MEDIARY_AI_BATCH=1) instead of running it here')]
        bool $enqueue = false,
    ): int {
        // ── 1. Resolve asset ──────────────────────────────────────────────────
        $asset = $this->resolveAsset($image);

        if ($asset === null) {
            $io->error("Asset not found for: {$image}");
            $io->comment('To add an asset, use the media ingest pipeline:');
            $io->listing([
                'POST /api/assets  with {"originalUrl": "' . $image . '"}',
                'or: bin/console app:debug:dispatch-download <url>',
            ]);
            return Command::FAILURE;
        }

        $io->title("Asset: {$asset->id}");
        $io->text("URL: {$asset->originalUrl}");

        // ── 2. Optional unlock ────────────────────────────────────────────────
        if ($unlock && $asset->aiLocked) {
            $asset->aiLocked = false;
            $this->entityManager->flush();
            $io->comment('Asset unlocked.');
        }

        // ── 2b. Queue one task through the workflow ──────────────────────────
        // The sync paths below always win over batching (they are the debugging paths); this is
        // the way to put a task on the ordinary ai_task transition, where the batch submitter
        // picks it up.
        if ($enqueue) {
            if ($task === null || !$this->taskRegistry->has($task)) {
                $io->error('--enqueue needs a known task name.');
                return Command::FAILURE;
            }
            if ($asset->aiLocked) {
                $io->error('Asset is AI-locked (a batch or task is in flight); not queueing.');
                return Command::FAILURE;
            }
            $this->runner->enqueue($asset, [$task]);
            $io->success(sprintf('Queued %s on %s (aiQueue: %s).', $task, $asset->id, implode(', ', $asset->aiQueue)));

            return Command::SUCCESS;
        }

        // ── 3. Optional pipeline enqueue ──────────────────────────────────────
        if ($pipeline !== null) {
            $tasks = match (strtolower($pipeline)) {
                'quick'  => AssetAiTask::quickScanPipeline(),
                'full'   => AssetAiTask::fullEnrichmentPipeline(),
                default  => null,
            };

            if ($tasks === null) {
                $io->error("Unknown pipeline \"{$pipeline}\". Valid values: quick, full");
                return Command::FAILURE;
            }

            if ($reset) {
                $asset->aiQueue = [];
            }

            $this->runner->enqueue($asset, $tasks);
            $io->success("Enqueued " . count($tasks) . " tasks ({$pipeline} pipeline).");
        }

        // ── 4. Status display ─────────────────────────────────────────────────
        $this->printStatus($io, $asset);

        if ($status) {
            return Command::SUCCESS;
        }

        // ── 5. Direct task execution ──────────────────────────────────────────
        if ($task !== null) {
            return $this->runNamedTask($io, $asset, $task, $json, $force);
        }

        // ── 6. Queue-driven execution ─────────────────────────────────────────
        if ($all) {
            if (empty($asset->aiQueue)) {
                $io->warning('aiQueue is empty. Use --pipeline=quick or --pipeline=full to enqueue tasks.');
                return Command::SUCCESS;
            }
            $ran = $this->runner->runAll($asset);
            $io->success('Ran ' . count($ran) . ' task(s): ' . implode(', ', $ran));
            $this->printCompleted($io, $asset, $json);
            return Command::SUCCESS;
        }

        if ($next || !empty($asset->aiQueue)) {
            if (empty($asset->aiQueue)) {
                $io->warning('aiQueue is empty.');
                return Command::SUCCESS;
            }
            $ran = $this->runner->runNext($asset);
            if ($ran !== null) {
                $io->success("Ran task: {$ran}");
                $this->printLastResult($io, $asset, $ran, $json);
            }
            return Command::SUCCESS;
        }

        // ── 7. No action specified → help ─────────────────────────────────────
        $io->note('No action specified. Use one of:');
        $io->listing([
            'bin/console media:task <url> <task>          — run a specific task',
            'bin/console media:task <url> --next          — run next queued task',
            'bin/console media:task <url> --all           — drain the queue',
            'bin/console media:task <url> --pipeline=quick — enqueue quick pipeline',
            'bin/console media:task <url> --pipeline=full  — enqueue full pipeline',
            'bin/console media:task <url> --status        — show status only',
        ]);

        $io->section('Available task names:');
        $rows = [];
        foreach ($this->taskRegistry->getTaskMap() as $name => $serviceId) {
            $rows[] = [$name, basename(str_replace('\\', '/', $serviceId))];
        }
        $io->table(['Task name', 'Handler'], $rows);

        return Command::SUCCESS;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveAsset(string $imageRef): ?Asset
    {
        // 16-char hex ID
        if (preg_match('/^[0-9a-f]{16}$/', $imageRef)) {
            return $this->assetRepository->find($imageRef);
        }

        // URL
        return $this->assetRepository->findOneByUrl($imageRef);
    }

    private function runNamedTask(SymfonyStyle $io, Asset $asset, string $taskName, bool $json, bool $force = false): int
    {
        // Validate task name
        if (!$this->taskRegistry->has($taskName)) {
            $io->error("Unknown task \"{$taskName}\".");
            $io->comment('Valid task names: ' . implode(', ', array_keys($this->taskRegistry->getTaskMap())));
            return Command::FAILURE;
        }

        $io->text("Running task <info>{$taskName}</info>…");
        $ran = $this->runner->runNamed($asset, $taskName, $force);

        if ($ran === null) {
            $io->warning('Task was not run (asset locked or queue error).');
            return Command::FAILURE;
        }

        $io->success("Task {$ran} completed.");
        $this->printLastResult($io, $asset, $ran, $json);

        return Command::SUCCESS;
    }

    private function printStatus(SymfonyStyle $io, Asset $asset): void
    {
        $io->section('Pipeline status');

        $io->definitionList(
            ['Marking'   => $asset->marking],
            ['AI locked' => $asset->aiLocked ? '<error>YES</error>' : 'no'],
            ['Queue'     => empty($asset->aiQueue)
                ? '<comment>empty</comment>'
                : implode(' → ', $asset->aiQueue)],
            ['Completed' => count($asset->aiCompleted) . ' task(s)'],
        );

        if (!empty($asset->aiCompleted)) {
            $rows = [];
            foreach ($asset->aiCompleted as $entry) {
                $failed  = !empty($entry['result']['failed']);
                $skipped = !empty($entry['result']['skipped']);
                $status  = $failed ? '<error>FAILED</error>' : ($skipped ? '<comment>skipped</comment>' : '<info>ok</info>');
                $rows[]  = [$entry['task'], $entry['at'], $status];
            }
            $io->table(['Task', 'Completed at', 'Status'], $rows);
        }
    }

    private function printLastResult(SymfonyStyle $io, Asset $asset, string $taskName, bool $json): void
    {
        // Find the most recent entry for this task
        $entry = null;
        foreach (array_reverse($asset->aiCompleted) as $e) {
            if ($e['task'] === $taskName) {
                $entry = $e;
                break;
            }
        }

        if ($entry === null) {
            return;
        }

        $io->section("Result: {$taskName}");

        if ($json) {
            $io->writeln(json_encode($entry['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }

        $this->prettyPrintResult($io, $entry['result']);
    }

    private function printCompleted(SymfonyStyle $io, Asset $asset, bool $json): void
    {
        foreach ($asset->aiCompleted as $entry) {
            $io->section("Result: {$entry['task']} @ {$entry['at']}");
            if ($json) {
                $io->writeln(json_encode($entry['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->prettyPrintResult($io, $entry['result']);
            }
        }
    }

    private function prettyPrintResult(SymfonyStyle $io, array $result): void
    {
        if (!empty($result['failed'])) {
            $io->error($result['error'] ?? 'Unknown error');
            return;
        }
        if (!empty($result['skipped'])) {
            $io->comment('Skipped: ' . ($result['reason'] ?? ''));
            return;
        }

        foreach ($result as $key => $value) {
            if ($key === 'raw_response') {
                continue; // skip verbose Mistral raw
            }
            if (\is_array($value)) {
                if (empty($value)) {
                    $io->text("<info>{$key}:</info> (none)");
                } elseif (isset($value[0]) && \is_array($value[0])) {
                    // array of objects (e.g. blocks)
                    $io->text("<info>{$key}:</info>");
                    foreach ($value as $item) {
                        $io->text('  ' . json_encode($item, JSON_UNESCAPED_UNICODE));
                    }
                } else {
                    $io->text("<info>{$key}:</info> " . implode(', ', $value));
                }
            } else {
                $display = $value === null ? '<comment>null</comment>' : (string) $value;
                $io->text("<info>{$key}:</info> {$display}");
            }
        }
    }
}
