<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Ai\AssetAiBatchSubmitter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Message\ApplyBatchResultsMessage;
use Tacman\AiBatch\Message\PollBatchesMessage;
use Tacman\AiBatch\Service\BatchClients;

/**
 * Scheduler-driven poll of mediary's in-flight provider batches (PollBatchesTask, every 2 min),
 * for every provider: the AiBatch row says which client to ask.
 *
 * Re-check each job's status and fold it onto its row. When a job ends, archive its raw result
 * lines to S3 (durable: providers delete batch files after a few weeks) and hand off to
 * ApplyBatchResultsMessageHandler.
 *
 * Asset-task batches (AssetAiBatchSubmitter) are handed off on ANY terminal status, a failed or
 * timed-out job included, because their assets are locked until applied. And one that has ended
 * but not landed (an apply that threw) is handed off again on every poll until it is `applied`.
 * The observe batches (media:batch-observe) keep their old rule: completed only, once.
 */
#[AsMessageHandler]
final class PollBatchesMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BatchClients $clients,
        #[Autowire(service: 'archive.storage')]
        private readonly FilesystemOperator $storage,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The same poll the scheduler runs every two minutes, on demand -- for watching a batch land,
     * and for getting one moving again after a provider or S3 outage without waiting for the tick.
     */
    #[AsCommand('media:ai-batch:poll', 'Poll in-flight AI batches now and apply any that have finished')]
    public function pollNow(
        SymfonyStyle $io,
        #[Option('Keep polling until every in-flight batch is done')] bool $wait = false,
        #[Option('Seconds between polls when waiting')] int $every = 15,
    ): int {
        $repo = $this->em->getRepository(AiBatch::class);
        do {
            $this->__invoke(new PollBatchesMessage());
            $this->em->clear();
            $inFlight = $repo->findBy(['status' => ['submitted', 'processing']]);
            foreach ($repo->findBy([], ['id' => 'DESC'], 10) as $batch) {
                $io->writeln(sprintf(
                    '  <info>%d</info> %s/%s %s — %d requested, %d done, %d applied%s',
                    $batch->id, $batch->provider, $batch->task, $batch->status,
                    $batch->requestCount, $batch->completedCount, $batch->appliedCount,
                    $batch->providerBatchId ? ' · ' . $batch->providerBatchId : '',
                ));
            }
            if ($wait && $inFlight !== []) {
                sleep(max(1, $every));
            }
        } while ($wait && $inFlight !== []);

        return Command::SUCCESS;
    }

    #[AsCommand('media:ai-batch:verify', 'Read archived results without contacting the AI provider and verify their checksum')]
    public function verifyArchive(SymfonyStyle $io, #[Argument('Local AiBatch ID')] int $id): int
    {
        $batch = $this->em->find(AiBatch::class, $id);
        if (!$batch instanceof AiBatch || $batch->savedResultPath === null) {
            $io->error('No archived result for this batch.');
            return Command::FAILURE;
        }
        $contents = $this->storage->read($batch->savedResultPath);
        $expected = $batch->meta['resultArchive']['sha256'] ?? null;
        if (!is_string($expected) || !hash_equals($expected, hash('sha256', $contents))) {
            $io->error('Missing archive receipt or checksum mismatch.');
            return Command::FAILURE;
        }
        $count = $input = $output = 0;
        $errors = [];
        foreach (explode("\n", trim($contents)) as $line) {
            if ($line === "") { continue; }
            $result = \Tacman\AiBatch\Model\BatchResult::fromProviderLine($batch->provider, json_decode($line, true, 512, JSON_THROW_ON_ERROR));
            ++$count;
            if (!$result->success) {
                $errors[] = [$result->customId, $result->errorCode, preg_replace('~https?://[^\s]+~', '[URL]', (string) $result->error)];
            }
            $input += $result->promptTokens;
            $output += $result->outputTokens;
        }
        if ($errors !== []) { $io->table(['Asset', 'Error code', 'Error'], $errors); }
        $io->success(sprintf('%d results recovered from archive; SHA-256 verified; %d bytes; %d input / %d output tokens. No provider requests.', $count, strlen($contents), $input, $output));
        return Command::SUCCESS;
    }

    public function __invoke(PollBatchesMessage $message): void
    {
        $repo = $this->em->getRepository(AiBatch::class);

        // Ended, not landed: a previous apply threw. Try again. (First, so a job that ends in
        // the loop below is handed off once per poll, not twice.)
        foreach ($repo->findBy(['status' => ['completed', 'failed']]) as $batch) {
            if ($batch->savedResultPath === null) {
                // A previous archive attempt may have failed. Retry before applying.
                $this->poll($batch);
            } elseif (self::isAssetTask($batch) || $batch->status === 'completed') {
                $this->handOff($batch);
            }
        }

        foreach ($repo->findBy(['status' => ['submitted', 'processing']]) as $batch) {
            $this->poll($batch);
        }
    }

    private function poll(AiBatch $batch): void
    {
        if ($batch->providerBatchId === null || !$this->clients->has($batch->provider)) {
            return;
        }
        $client = $this->clients->get($batch->provider);

        try {
            $job = $client->checkBatch($batch->providerBatchId);
        } catch (\Throwable $e) {
            $this->logger->warning('ai-batch poll failed for {provider} {id}: {err}', ['provider' => $batch->provider, 'id' => $batch->providerBatchId, 'err' => $e->getMessage()]);

            return;
        }

        $batch->applyProviderStatus($job->status, $job->completedCount, $job->failedCount, $job->outputFileId, $job->errorFileId);
        if (!$job->isTerminal()) {
            $this->em->flush();

            return;
        }

        if ($batch->savedResultPath === null && ($job->outputFileId !== null || $job->errorFileId !== null)) {
            try {
                $lines = [];
                foreach ($client->fetchResults($job) as $result) {
                    $lines[] = json_encode($result->raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (count($lines) < $job->completedCount + $job->failedCount) {
                    throw new \RuntimeException('Provider result archive is incomplete; keeping batch pending.');
                }
                $key = sprintf('ai-batch/%s/%s/%s.jsonl', trim((string) ($batch->datasetKey ?? '_'), '/') ?: '_', $batch->task, $batch->providerBatchId);
                $contents = implode("\n", $lines) . "\n";
                $this->storage->write($key, $contents, ['visibility' => 'private']);
                $checksum = hash('sha256', $contents);
                if (!hash_equals($checksum, hash('sha256', $this->storage->read($key)))) {
                    throw new \RuntimeException('Archived batch failed read-back verification.');
                }
                $batch->meta['resultArchive'] = [
                    'sha256' => $checksum,
                    'bytes' => strlen($contents),
                    'lines' => count($lines),
                    'verifiedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ];
                $batch->savedResultPath = $key;
                $this->logger->info('ai-batch {id} {status} → {key} ({n} lines)', ['id' => $batch->providerBatchId, 'status' => $job->status, 'key' => $key, 'n' => \count($lines)]);
            } catch (\Throwable $e) {
                // Keep the terminal job pending so the next poll retries durable archival.
                $this->logger->warning('ai-batch {id}: archiving results failed: {err}', ['id' => $batch->providerBatchId, 'err' => $e->getMessage()]);
            }
        }
        $this->em->flush();

        // A failed job with no output can still unlock its assets. Successful output
        // must survive independently of the provider before any claims are applied.
        $needsArchive = $job->isComplete() || $job->outputFileId !== null || $job->errorFileId !== null;
        if ((!$needsArchive || $batch->savedResultPath !== null)
            && (self::isAssetTask($batch) || $job->isComplete())) {
            $this->handOff($batch);
        }
    }

    private function handOff(AiBatch $batch): void
    {
        try {
            $this->bus->dispatch(new ApplyBatchResultsMessage($batch->id));
        } catch (\Throwable $e) {
            // An asset-task batch is retried on the next poll; see __invoke().
            $this->logger->error('ai-batch {id}: apply failed: {err}', ['id' => $batch->id, 'err' => $e->getMessage()]);
        }
    }

    private static function isAssetTask(AiBatch $batch): bool
    {
        return ($batch->meta['kind'] ?? null) === AssetAiBatchSubmitter::KIND;
    }
}
