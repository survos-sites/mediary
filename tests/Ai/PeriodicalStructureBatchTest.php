<?php

declare(strict_types=1);
namespace App\Tests\Ai;
use App\Ai\AssetSubject;
use App\Entity\Asset;
use PHPUnit\Framework\TestCase;
use Survos\AiWorkflowBundle\Task\Analysis\PeriodicalStructureTask;
use Symfony\Component\HttpClient\MockHttpClient;
final class PeriodicalStructureBatchTest extends TestCase
{
    public function testExistingAssetSubjectSuppliesTextOnlyBatchRequest(): void
    {
        $asset=Asset::fromOriginalUrl('https://example.org/page.jpg');
        $context=['scope'=>'cron-america/test',PeriodicalStructureTask::INPUT=>[
            'width'=>100,'height'=>200,'blocks'=>[['id'=>'b1','text'=>'Original text','box'=>[0,0,10,20]]],
        ]];
        $subject=new AssetSubject($asset,$context);
        $task=new PeriodicalStructureTask(new MockHttpClient(), 'unused-test-key');
        self::assertTrue($task->supportsBatch($subject));
        self::assertSame('mistral',$task->batchProvider());
        self::assertSame('cron-america/test',$subject->getWorkflowScope());
        $request=$task->batchRequest($subject);
        self::assertSame('/v1/chat/completions',$request['endpoint']);
        self::assertStringContainsString('Original text',json_encode($request));
        self::assertStringNotContainsString('page.jpg',json_encode($request));
    }
}
