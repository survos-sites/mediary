<?php

declare(strict_types=1);
namespace App\Tests\Ai;

use App\Ai\AssetAiBatchSubmitter;
use App\MessageHandler\PollBatchesMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Tacman\AiBatch\Contract\BatchCapablePlatformInterface;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Message\PollBatchesMessage;
use Tacman\AiBatch\Model\{BatchJob, BatchResult};
use Tacman\AiBatch\Service\BatchClients;

final class BatchArchiveTest extends TestCase
{
    public function testArchiveFailureRetriesBeforeApplying(): void
    {
        $batch = new AiBatch();
        $batch->id = 99;
        $batch->providerBatchId = 'test-batch';
        $batch->provider = 'mistral';
        $batch->status = 'completed';
        $batch->meta = ['kind' => AssetAiBatchSubmitter::KIND];
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturnCallback(fn($criteria) => in_array('completed', $criteria['status']) ? [$batch] : []);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $client = $this->createStub(BatchCapablePlatformInterface::class);
        $client->method('checkBatch')->willReturn(new BatchJob('test-batch', 'completed', 'mistral', outputFileId: 'output', completedCount: 1));
        $client->method('fetchResults')->willReturn([new BatchResult('page-1', 'text', true, raw: ['custom_id' => 'page-1'])]);
        $clients = new BatchClients(new ServiceLocator(['mistral' => fn() => $client]));
        $storage = $this->createMock(FilesystemOperator::class);
        $written = null; $attempt = 0;
        $storage->expects(self::exactly(2))->method('write')->willReturnCallback(function($key, $contents, $config) use (&$written, &$attempt) {
            self::assertSame('private', $config['visibility']);
            if (++$attempt === 1) { throw new \RuntimeException('S3 unavailable'); }
            $written = $contents;
        });
        $storage->method('read')->willReturnCallback(function() use (&$written) { return $written; });
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(function($message) use ($batch) {
            self::assertNotNull($batch->savedResultPath);
            self::assertSame(1, $batch->meta['resultArchive']['lines']);
            return new Envelope($message);
        });
        $handler = new PollBatchesMessageHandler($em, $clients, $storage, $bus, new NullLogger());
        $handler(new PollBatchesMessage());
        self::assertNull($batch->savedResultPath);
        $handler(new PollBatchesMessage());
        self::assertSame(hash('sha256', $written), $batch->meta['resultArchive']['sha256']);
    }
}
