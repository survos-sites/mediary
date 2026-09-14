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
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
final class AssetRecoveryTest extends TestCase
{
    public function testOnlySuccessfulTasksCanBeReused(): void
    {
        $asset = new Asset();
        $asset->aiCompleted = [
            ['task' => 'observe', 'result' => ['failed' => true]],
            ['task' => 'ocr_tesseract', 'result' => ['skipped' => true]],
        ];
        self::assertFalse($asset->hasSuccessfulAiTask('observe'));
        self::assertFalse($asset->hasSuccessfulAiTask('ocr_tesseract'));
        $asset->aiCompleted[] = ['task' => 'observe', 'result' => ['cached' => false, 'response' => ['caption' => 'A bridge']]];
        self::assertTrue($asset->hasSuccessfulAiTask('observe'));
        self::assertFalse($asset->hasSuccessfulAiTask('another_task'));
    }
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
        $urls = new ImgproxyUrlBuilder(host: 'https://images.example');
        $subject = new AssetSubject($asset, imageUrls: $urls);
        self::assertSame($urls->aiThumbnail($asset->archiveUrl), $subject->getAiSmallUrl());
    }
    public function testBatchOverrideWinsAndStillSupportsVisionThumbnails(): void
    {
        $asset = new Asset();
        $asset->originalUrl = 'https://provider.example/image.jpg';
        $asset->archiveUrl = 'https://storage.example/image.jpg';
        $override = 'https://storage.example/image.jpg?signature=batch';
        $urls = new ImgproxyUrlBuilder(host: 'https://images.example');
        $subject = new AssetSubject($asset, ['image_url' => $override], $urls);
        self::assertSame($override, $subject->getWorkflowImageUrl());
        self::assertSame($urls->aiThumbnail($override), $subject->getAiSmallUrl());
        self::assertSame($asset->originalUrl, $subject->getWorkflowAudioUrl());
        self::assertSame($asset->archiveUrl, (new AssetSubject($asset, ['image_url' => '']))->getWorkflowImageUrl());
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
