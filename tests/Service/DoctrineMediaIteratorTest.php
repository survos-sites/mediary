<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DoctrineMediaIterator;
use App\Ai\Comparison\ModelComparisonClient;
use App\Command\MediaIterateCommand;
use App\Event\MediaIterationEvent;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;

final class DoctrineMediaIteratorTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([], true);
        $config->enableNativeLazyObjects(true);
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(IterationFixture::class)]);
        foreach ([1, 2, 3, 4, 5] as $id) {
            $row = new IterationFixture();
            $row->id = $id;
            $row->dataset = $id === 2 ? 'other' : 'photos';
            $this->em->persist($row);
        }
        $this->em->flush();
        $this->em->clear();
    }

    public function testKeysetPagesRespectFiltersAndGlobalLimit(): void
    {
        $iterator = new DoctrineMediaIterator($this->em);
        $ids = [];
        foreach ($iterator->iterate(IterationFixture::class, ['dataset=photos'], 3, 2) as $entity) {
            $ids[] = $entity->id;
            $entity->dataset = 'not persisted';
        }
        self::assertSame([1, 3, 4], $ids);
        self::assertSame('photos', $this->em->find(IterationFixture::class, 1)->dataset);
    }

    public function testUnknownFilterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array((new DoctrineMediaIterator($this->em))->iterate(IterationFixture::class, ['id OR 1=1']));
    }

    public function testZeroLimitIsNotAnUnboundedQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array((new DoctrineMediaIterator($this->em))->iterate(IterationFixture::class, [], 0));
    }

    public function testCommandDispatchesOnlySelectedEntitiesAndDryRunSkipsListeners(): void
    {
        $events = new EventDispatcher();
        $seen = [];
        $events->addListener(MediaIterationEvent::class, function (MediaIterationEvent $event) use (&$seen): void {
            $seen[] = $event->entity->id;
        });
        $command = new MediaIterateCommand(new DoctrineMediaIterator($this->em), $events, new ModelComparisonClient(new MockHttpClient(), []));
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        self::assertSame(0, $command($io, entity: IterationFixture::class, limit: 2, pageSize: 1, filter: ['dataset=photos'], dryRun: true));
        self::assertSame([], $seen);
        self::assertSame(0, $command($io, entity: IterationFixture::class, limit: 2, pageSize: 1, filter: ['dataset=photos']));
        self::assertSame([1, 3], $seen);
    }

    public function testCommandPersistsFailuresContinuesAndRefusesOverwrite(): void
    {
        $events = new EventDispatcher();
        $events->addListener(MediaIterationEvent::class, function (MediaIterationEvent $event): void {
            if ($event->entity->id === 1) {
                throw new \RuntimeException('sensitive provider diagnostic');
            }
            $event->results['test'] = ['status' => 'ok', 'output' => 'a result'];
        });
        $command = new MediaIterateCommand(new DoctrineMediaIterator($this->em), $events, new ModelComparisonClient(new MockHttpClient(), []));
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $path = sys_get_temp_dir().'/media-iterate-test-'.bin2hex(random_bytes(8)).'.jsonl';
        try {
            self::assertSame(1, $command($io, entity: IterationFixture::class, limit: 2, output: $path));
            $saved = file_get_contents($path);
            $rows = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES));
            self::assertCount(2, $rows);
            self::assertSame('1', $rows[0]['id']);
            self::assertSame('error', $rows[0]['results']['iteration']['status']);
            self::assertStringNotContainsString('sensitive', $saved);
            self::assertSame('a result', $rows[1]['results']['test']['output']);
            self::assertSame(1, $command($io, entity: IterationFixture::class, limit: 2, output: $path));
            self::assertSame($saved, file_get_contents($path));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testImageUrlDryRunNeedsNoMappedAssetAndRejectsDatabaseFilters(): void
    {
        $events = new EventDispatcher();
        $events->addListener(MediaIterationEvent::class, static function (): void {
            self::fail('Dry runs must not dispatch inference.');
        });
        $command = new MediaIterateCommand(new DoctrineMediaIterator($this->em), $events, new ModelComparisonClient(new MockHttpClient(), ['test' => []]));
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        self::assertSame(0, $command($io, compare: true, models: 'test', imageUrl: 'https://example.org/crop.jpg', dryRun: true));
        self::assertStringContainsString('Visited 1 entities', $output->fetch());
        self::assertSame(1, $command($io, compare: true, models: 'test', imageUrl: 'https://example.org/crop.jpg', filter: ['id=123'], dryRun: true));
    }
}

#[ORM\Entity]
final class IterationFixture
{
    #[ORM\Id, ORM\Column]
    public int $id;

    #[ORM\Column]
    public string $dataset;
}
