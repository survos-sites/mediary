<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AssetAiTask;
use App\Ai\AssetAiTaskRunner;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Load curated test-image fixtures and optionally run AI tasks on them.
 *
 * Examples:
 *   bin/console media:fixture --list
 *   bin/console media:fixture --load
 *   bin/console media:fixture --load --tag=handwriting
 *   bin/console media:fixture --load --task=ocr_mistral
 *   bin/console media:fixture --load --id=hw_lincoln_letter --task=transcribe_handwriting
 */
#[AsCommand(
    name: 'media:fixture',
    description: 'Load curated test-image fixtures and optionally run AI tasks on them',
)]
final class MediaFixtureCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetRepository $assetRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('list',   'l', InputOption::VALUE_NONE, 'List all fixtures without loading')
            ->addOption('load',   null, InputOption::VALUE_NONE, 'Create Asset rows for fixtures (skips existing)')
            ->addOption('reset',  null, InputOption::VALUE_NONE, 'Reset AI queue/completed before running tasks')
            ->addOption('id',     null, InputOption::VALUE_REQUIRED, 'Only process fixture with this id')
            ->addOption('tag',    null, InputOption::VALUE_REQUIRED, 'Only process fixtures whose task list includes this task name')
            ->addOption('task',   null, InputOption::VALUE_REQUIRED, 'Run this AI task on loaded fixtures (e.g. ocr_mistral)')
            ->addOption('pipeline', null, InputOption::VALUE_REQUIRED, 'Run named pipeline: quick or full')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fixtureFile = $this->projectDir . '/config/fixtures/test_images.yaml';
        if (!file_exists($fixtureFile)) {
            $io->error("Fixture file not found: {$fixtureFile}");
            return Command::FAILURE;
        }

        $config   = Yaml::parseFile($fixtureFile);
        $fixtures = $config['fixtures'] ?? [];

        // ── Filter ────────────────────────────────────────────────────────────
        if ($filterId = $input->getOption('id')) {
            $fixtures = array_filter($fixtures, fn(array $f) => $f['id'] === $filterId);
        }
        if ($filterTag = $input->getOption('tag')) {
            $fixtures = array_filter($fixtures, fn(array $f) => in_array($filterTag, $f['tasks'] ?? [], true));
        }
        $fixtures = array_values($fixtures);

        if (empty($fixtures)) {
            $io->warning('No fixtures matched the given filters.');
            return Command::SUCCESS;
        }

        // ── List mode ─────────────────────────────────────────────────────────
        if ($input->getOption('list')) {
            $rows = [];
            foreach ($fixtures as $f) {
                $rows[] = [
                    $f['id'],
                    $f['label'],
                    implode(', ', $f['tasks'] ?? []),
                    $f['notes'] ?? '',
                ];
            }
            $io->table(['ID', 'Label', 'Tasks', 'Notes'], $rows);
            return Command::SUCCESS;
        }

        // ── Load mode ─────────────────────────────────────────────────────────
        if (!$input->getOption('load') && !$input->getOption('task') && !$input->getOption('pipeline')) {
            $io->note('Nothing to do. Use --list, --load, or --task=<name>.');
            return Command::SUCCESS;
        }

        $taskName     = $input->getOption('task');
        $pipelineName = $input->getOption('pipeline');

        if ($taskName !== null && !$this->taskRegistry->has($taskName)) {
            $io->error("Unknown task: {$taskName}. Valid values: " . implode(', ', array_keys($this->taskRegistry->getTaskMap())));
            return Command::FAILURE;
        }

        $pipelineTasks = null;
        if ($pipelineName !== null) {
            $pipelineTasks = match ($pipelineName) {
                'quick' => AssetAiTask::quickScanPipeline(),
                'full'  => AssetAiTask::fullEnrichmentPipeline(),
                default => null,
            };
            if ($pipelineTasks === null) {
                $io->error("Unknown pipeline: {$pipelineName}. Use 'quick' or 'full'.");
                return Command::FAILURE;
            }
        }

        foreach ($fixtures as $f) {
            $io->section($f['label']);

            // ── Find or create Asset ──────────────────────────────────────────
            $asset = $this->assetRepository->findOneBy(['originalUrl' => $f['url']]);

            if ($asset === null) {
                $asset       = Asset::fromOriginalUrl($f['url']);
                $asset->mime = $f['mime'] ?? null;
                $this->em->persist($asset);
                $this->em->flush();
                $io->text("  Created asset <info>{$asset->id}</info>");
            } else {
                $io->text("  Existing asset <info>{$asset->id}</info>");
            }

            // ── Optional reset ────────────────────────────────────────────────
            if ($input->getOption('reset')) {
                $asset->aiQueue     = [];
                $asset->aiCompleted = [];
                $asset->aiLocked    = false;
                $this->em->flush();
                $io->text('  AI state reset.');
            }

            // ── Run task ──────────────────────────────────────────────────────
            if ($taskName !== null) {
                // Check the fixture declares this task (warn but don't skip)
                if (!in_array($taskName, $f['tasks'] ?? [], true)) {
                    $io->warning("  Fixture '{$f['id']}' does not list task '{$taskName}' — running anyway.");
                }
                $asset->aiQueue = [$taskName, ...$asset->aiQueue];
                $this->em->flush();

                try {
                    $this->runner->runNext($asset);
                    $io->success("  Task {$taskName} completed.");
                } catch (\Throwable $e) {
                    $io->error("  Task {$taskName} failed: " . $e->getMessage());
                }
            }

            // ── Run pipeline ──────────────────────────────────────────────────
            if ($pipelineTasks !== null) {
                $this->runner->enqueue($asset, $pipelineTasks);
                $io->text("  Enqueued {$pipelineName} pipeline (" . count($pipelineTasks) . " tasks).");

                try {
                    $this->runner->runAll($asset);
                    $io->success("  Pipeline {$pipelineName} completed.");
                } catch (\Throwable $e) {
                    $io->error("  Pipeline {$pipelineName} failed at task: " . $e->getMessage());
                }
            }

            // Print asset URL for quick browser access
            $io->text("  URL: /media/{$asset->id}");
        }

        return Command::SUCCESS;
    }
}
