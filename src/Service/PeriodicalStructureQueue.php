<?php

declare(strict_types=1);

namespace App\Service;

use App\Ai\{AssetAiTaskRunner, AssetSubject};
use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;
use Survos\AiWorkflowBundle\Task\Analysis\PeriodicalStructureTask;
use Survos\DataContracts\Util\MediaIdentity;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\{AsCommand, Argument, Option};
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Feed registered page assets into the ordinary ai_task transition and its batch listener. */
final readonly class PeriodicalStructureQueue
{
    public function __construct(
        private EntityManagerInterface $em,
        private AssetAiTaskRunner $runner,
        private AssetRegistry $registry,
        private PeriodicalStructureTask $task,
        #[Autowire('%env(bool:MEDIARY_AI_BATCH)%')] private bool $batchEnabled,
    ) {}

    #[AsCommand('periodical:structure:queue', 'Queue prepared text/layout on registered page assets through the existing batched ai_task workflow')]
    public function queue(SymfonyStyle $io, #[Argument('Harvest structure/requests.jsonl')] string $file,
        #[Option('Queue transitions; otherwise validate only')] bool $run = false,
        #[Option('Register missing page URLs through AssetRegistry')] bool $register = false,
        #[Option('Bounded page count, at most 30')] int $limit = 12): int
    {
        if ($limit < 1 || $limit > 30) { throw new \InvalidArgumentException('Limit must be 1–30 pages.'); }
        if ($run && !$this->batchEnabled) { throw new \RuntimeException('Enable MEDIARY_AI_BATCH=1 on the producer and worker to use transition batching.'); }
        $todo = []; $missing = 0;
        foreach (JsonlReader::open($file) as $row) {
            if (count($todo) + $missing >= $limit) { break; }
            $url = $row['assetUrl'] ?? null;
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) { throw new \UnexpectedValueException('Prepared page needs its registered assetUrl.'); }
            $asset = $this->em->find(Asset::class, MediaIdentity::idFromOriginalUrl($url));
            if (!$asset && !$register) { ++$missing; continue; }
            $asset ??= Asset::fromOriginalUrl($url);
            $page = $row['context'][PeriodicalStructureTask::INPUT];
            if (!hash_equals($row['sourceHash'], hash('sha256', json_encode($page, JSON_THROW_ON_ERROR)))) {
                throw new \UnexpectedValueException('Prepared page hash mismatch.');
            }
            if ($asset->aiLocked || $asset->aiQueue !== []) { throw new \RuntimeException('Selected asset already has AI work; leave it intact.'); }
            $context = [PeriodicalStructureTask::INPUT => $page, 'scope' => $row['dataset']];
            $this->task->batchRequest(new AssetSubject($asset, $context));
            $todo[] = [$asset, $context, $url];
        }
        if ($missing) {
            $io->error(sprintf('%d selected page assets are not registered. Register their URLs through the existing media:sync workflow first. No tasks queued.', $missing));
            return 1;
        }
        if ($run) {
            foreach ($todo as [$asset, $context, $url]) {
                $asset = $this->registry->ensureAsset($url, null, false, ['dataset' => $context['scope']]);
                $asset->context = array_replace($asset->context ?? [], $context);
                $this->runner->enqueue($asset, [PeriodicalStructureTask::TASK]);
            }
        }
        $io->success(sprintf('%d pages %s; provider submission, polling, claims and unlock use AssetAiBatchSubmitter/AssetAiBatchApplier.', count($todo), $run ? 'queued' : 'validated'));
        return 0;
    }
}
