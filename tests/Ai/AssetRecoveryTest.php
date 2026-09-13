<?php
declare(strict_types=1);
namespace App\Tests\Ai;
use App\Ai\AssetSubject;
use App\Ai\AssetAiExecutor;
use App\Entity\Asset;
use App\Service\SidecarService;
use App\Workflow\AssetWorkflow;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\Contracts\Service\ServiceProviderInterface;
final class AssetRecoveryTest extends TestCase
{
    public function testArchivedImageIsUsedWithoutChangingIdentity(): void
    {
        $asset = new Asset();
        $asset->id = '1234567890abcdef';
        $asset->originalUrl = 'https://provider.example/image.jpg';
        $subject = new AssetSubject($asset);
        self::assertSame($asset->originalUrl, $subject->getWorkflowImageUrl());
        $asset->archiveUrl = 'https://storage.example/image.jpg';
        self::assertSame($asset->archiveUrl, $subject->getWorkflowImageUrl());
        self::assertSame($asset->id, $subject->getWorkflowSubjectId());
    }
    public function testExecutorFailureLeavesTaskPending(): void
    {
        $provider = new class implements ServiceProviderInterface {
            public function get(string $id): mixed { throw new \RuntimeException('Provider timeout'); }
            public function has(string $id): bool { return true; }
            public function getProvidedServices(): array { return []; }
        };
        $executor = new AssetAiExecutor(new TaskRegistry($provider, ['observe' => 'observe']), new SidecarService());
        $class = new \ReflectionClass(AssetWorkflow::class);
        $workflow = $class->newInstanceWithoutConstructor();
        $class->getProperty('executor')->setValue($workflow, $executor);
        $class->getProperty('logger')->setValue($workflow, new NullLogger());
        $asset = new Asset();
        $asset->id = '1234567890abcdef';
        $asset->aiQueue = ['observe'];
        try {
            $workflow->runNextAiTask($asset);
            self::fail('Failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('Provider timeout', $e->getMessage());
        }
        self::assertSame(['observe'], $asset->aiQueue);
        self::assertSame([], $asset->aiCompleted);
    }
}
