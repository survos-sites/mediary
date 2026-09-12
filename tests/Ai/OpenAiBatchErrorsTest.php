<?php

declare(strict_types=1);
namespace App\Tests\Ai;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tacman\AiBatch\Model\BatchJob;
use Tacman\AiBatch\Service\OpenAiBatchClient;
final class OpenAiBatchErrorsTest extends TestCase
{
    public function testErrorOnlyBatchIsCollectedAndParsedAsFailure(): void
    {
        $raw = ['custom_id'=>'image1','response'=>['status_code'=>400,'body'=>['error'=>['message'=>'Image not found','code'=>'invalid_image_url']]]];
        $http = new MockHttpClient(function($method,$url) use ($raw) {
            self::assertStringEndsWith('/files/errors/content',$url);
            return new MockResponse(json_encode($raw)."\n");
        });
        $results = iterator_to_array((new OpenAiBatchClient($http,'test'))->fetchResults(new BatchJob('job','completed','openai',errorFileId:'errors',failedCount:1)));
        self::assertCount(1,$results);
        self::assertFalse($results[0]->success);
        self::assertSame('invalid_image_url',$results[0]->errorCode);
    }
}
