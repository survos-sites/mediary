<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Service\OpenAiBatchClient;

/**
 * OpenAI batch "observe" for mediary: send each asset's image to the OpenAI Batch API and record the
 * result as observe:* claims. This batches the *vision* portion of observe (caption / description /
 * classification / tags) — the part a single OpenAI call can do; the full ssai observe pipeline also
 * runs non-batchable tools (Tesseract) and stays the synchronous path.
 *
 * Each request's custom_id is the Asset id (xxh3 of originalUrl); image_url is the public originalUrl
 * (OpenAI must be able to fetch it, so not the local imgproxy URL), detail=low. Chunks are submitted
 * and a tagged AiBatch row (datasetKey=scope, task='observe') is persisted per chunk; the scheduler
 * (PollBatchesTask → Poll/Apply handlers) then polls, archives to S3, and records claims.
 */
final class MediaBatchObserveService
{
    private const SYSTEM = 'You are a museum image analyst observing a single photograph. Return ONLY a JSON object with keys: caption (a short neutral title, <=80 chars, no trailing period), description (1-3 objective sentences of what is visibly depicted), classification (one of: photograph, document, artwork, object, other), tags (array of short keyword strings). Describe only what is visible; do not invent facts.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OpenAiBatchClient $client,
        private readonly AssetPresigner $presigner,
    ) {
    }

    #[AsCommand('media:batch-observe', 'Submit OpenAI vision "observe" batches for assets; the scheduler polls and records claims')]
    public function batchObserve(
        SymfonyStyle $io,
        #[Argument('Claim scope to record under (e.g. mus/fpus, or mediary)')] string $scope,
        #[Option('Explicit asset IDs for a bounded test; repeat for multiple assets')] array $assetId = [],
        #[Option('Max assets to include (0 = all eligible)')] int $limit = 0,
        #[Option('Requests per provider batch')] int $chunkSize = 2000,
        #[Option('OpenAI model')] string $model = 'gpt-4o-mini',
        #[Option('Image detail sent to OpenAI')] string $imageDetail = 'low',
        #[Option('Use original URLs instead of archive URLs')] bool $original = false,
        #[Option('Build but do not submit')] bool $dryRun = false,
    ): int {
        $qb = $this->em->getRepository(Asset::class)->createQueryBuilder('a')
            ->where('a.originalUrl IS NOT NULL');
        if ($assetId !== []) {
            $qb->andWhere('a.id IN (:ids)')->setParameter('ids', $assetId);
        }
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }
        /** @var list<Asset> $assets */
        $assets = $qb->getQuery()->getResult();
        if ($assets === []) {
            $io->warning('No assets with an originalUrl to observe.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('observe-batch: %d asset(s) → scope %s, chunks of <=%d', \count($assets), $scope, $chunkSize));

        $submitted = 0;
        foreach (array_chunk($assets, max(1, $chunkSize)) as $i => $chunk) {
            $lines = [];
            foreach ($chunk as $asset) {
                $lines[] = json_encode($this->requestLine($asset, $model, $imageDetail, $original), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $n = \count($lines);

            if ($dryRun) {
                $io->writeln(sprintf('  chunk %d: %d request(s) (dry run)', $i + 1, $n));
                continue;
            }

            $fileId = $this->client->uploadInputFile(implode("\n", $lines) . "\n");
            $job = $this->client->submitFromFileId($fileId);

            $batch = new AiBatch();
            $batch->datasetKey = $scope;
            $batch->task = 'observe';
            $batch->provider = 'openai';
            $batch->requestCount = $n;
            $batch->markSubmitted($job->id, $fileId);
            $this->em->persist($batch);
            $submitted++;
            $io->writeln(sprintf('  chunk %d → batch %s (%d req)', $i + 1, $job->id, $n));
        }

        if ($dryRun) {
            $io->note('Dry run — nothing submitted.');
            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('Submitted %d observe batch(es) for scope %s. The scheduler will poll, archive to S3, and record claims.', $submitted, $scope));
        $io->note('Ensure a scheduler consumer runs: bin/console messenger:consume scheduler_default');

        return Command::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function requestLine(Asset $asset, string $model, string $imageDetail, bool $original = false): array
    {
        return [
            'custom_id' => $asset->id,
            'method' => 'POST',
            'url' => '/v1/chat/completions',
            'body' => [
                'model' => $model,
                'temperature' => 0.2,
                'max_tokens' => 600,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM],
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => 'Observe this image and return the JSON object.'],
                        ['type' => 'image_url', 'image_url' => ['url' => ($original ? $asset->originalUrl : ($this->presigner->archiveUrl($asset) ?? $asset->originalUrl)), 'detail' => $imageDetail]],
                    ]],
                ],
            ],
        ];
    }
}
