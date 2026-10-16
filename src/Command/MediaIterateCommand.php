<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\Comparison\ModelComparisonClient;
use App\Entity\Asset;
use App\Event\MediaIterationEvent;
use App\Service\DoctrineMediaIterator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand('media:iterate', 'Iterate local Doctrine entities and dispatch an event; optionally compare image task models.')]
final class MediaIterateCommand
{
    public function __construct(
        private readonly DoctrineMediaIterator $iterator,
        private readonly EventDispatcherInterface $events,
        private readonly ModelComparisonClient $comparison,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Entity short name in App\\Entity, or a mapped FQCN')]
        string $entity = Asset::class,
        #[Option('Maximum entities to visit (positive; default 3)')]
        int $limit = 3,
        #[Option('Database page size')]
        int $pageSize = 100,
        #[Option('Exact mapped field=value filter; repeat to AND filters')]
        array $filter = [],
        #[Option('Invoke comparison listener (makes paid synchronous API calls)')]
        bool $compare = false,
        #[Option('Task whose prompt/image to compare')]
        string $task = 'observe',
        #[Option('Comma-separated profile names from config/services/media_comparison.yaml')]
        string $models = 'openai-mini,gemini-flash-lite',
        #[Option('Comparison crop in original-image pixels: x,y,width,height (Assets only)')]
        ?string $crop = null,
        #[Option('Compare one HTTP(S) image URL without creating a database Asset')]
        ?string $imageUrl = null,
        #[Option('New JSONL file; comparison defaults to var/comparisons/<timestamp>.jsonl')]
        ?string $output = null,
        #[Option('Select and print entities without dispatching events or calling models')]
        bool $dryRun = false,
    ): int {
        $class = str_contains($entity, '\\') ? $entity : 'App\\Entity\\'.$entity;
        $selectedModels = array_values(array_unique(array_filter(array_map('trim', explode(',', $models)))));
        $file = null;
        $count = $failures = 0;
        try {
            if ($imageUrl !== null && (!$compare || $filter !== [] || $class !== Asset::class || !preg_match('#^https?://#i', $imageUrl) || filter_var($imageUrl, FILTER_VALIDATE_URL) === false)) {
                throw new \InvalidArgumentException('--image-url requires --compare, an HTTP(S) URL, the default Asset entity and no database filters.');
            }
            if ($crop !== null && (!$compare || !preg_match('/^\d+,\d+,[1-9]\d*,[1-9]\d*$/D', $crop))) {
                throw new \InvalidArgumentException('Use --crop=x,y,width,height with --compare; offsets must be nonnegative and dimensions positive.');
            }
            if ($compare) {
                $this->comparison->validate($selectedModels, !$dryRun);
            }
            if (!$dryRun && ($compare || $output !== null)) {
                $output ??= 'var/comparisons/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.jsonl';
                $dir = dirname($output);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new \RuntimeException('Cannot create output directory.');
                }
                $file = @fopen($output, 'x');
                if ($file === false) {
                    throw new \RuntimeException('Cannot create output file (existing files are never overwritten).');
                }
            }
            if ($imageUrl !== null) {
                $asset = Asset::fromOriginalUrl($imageUrl);
                $items = [$asset->id => $asset];
            } else {
                $items = $this->iterator->iterate($class, $filter, $limit, $pageSize);
            }
            foreach ($items as $identifier => $item) {
                ++$count;
                $event = new MediaIterationEvent($item, ['compare' => $compare, 'task' => $task, 'models' => $selectedModels, 'crop' => $crop]);
                $id = (string) $identifier;
                $io->writeln(OutputFormatter::escape(($dryRun ? '[dry-run] ' : '').$id));
                if ($dryRun) {
                    continue;
                }
                try {
                    $this->events->dispatch($event);
                } catch (\Exception $e) {
                    $event->results['iteration'] = ['status' => 'error', 'error' => 'Listener failed ('.$e::class.').'];
                }
                if ($compare && $event->results === []) {
                    $event->results['iteration'] = ['status' => 'error', 'error' => 'No comparison listener produced a result.'];
                }
                foreach ($event->results as $name => $result) {
                    if (($result['status'] ?? 'ok') !== 'ok') {
                        ++$failures;
                    }
                    $io->writeln(OutputFormatter::escape(sprintf('  %s: %s; %s ms; USD %s', $name, $result['status'] ?? 'ok', $result['elapsed_ms'] ?? '-', isset($result['estimated_cost_usd']) ? sprintf('%.6f', $result['estimated_cost_usd']) : 'unknown')));
                }
                if (is_resource($file)) {
                    $line = json_encode(['at' => gmdate(DATE_ATOM), 'entity' => $class, 'id' => $id, 'results' => $event->results], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
                    if (fwrite($file, $line) !== strlen($line) || !fflush($file)) {
                        throw new \RuntimeException('Could not save the comparison result.');
                    }
                }
            }
        } catch (\Exception $e) {
            $io->error(OutputFormatter::escape($e->getMessage()));
            return Command::FAILURE;
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }
        $io->text(sprintf('Visited %d entities; %d failed results.', $count, $failures));
        if ($output !== null && !$dryRun) {
            $io->text('Saved: '.OutputFormatter::escape($output));
        }
        if ($count === 0) {
            $io->warning('No entities matched the selection.');
        }

        return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
