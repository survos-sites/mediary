<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AssetSubject;
use App\Ai\PdfOcrTask;
use App\Entity\Asset;
use PHPUnit\Framework\TestCase;
use Survos\AiWorkflowBundle\Task\TaskClaimMapper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PdfOcrTaskTest extends TestCase
{
    public function testMixedPdfKeepsPageIndicesAndOnlyOcrsScannedPages(): void
    {
        $calls = [];
        $responses = [
            ['id' => 'file', 'sha256' => str_repeat('a', 64), 'pages' => 2, 'bytes' => 100],
            ['text' => 'Existing text'], ['words' => []],
            ['text' => ''], ['text' => 'Reconocido', 'blocks' => [], 'width' => 3000, 'height' => 4000],
        ];
        $client = new MockHttpClient(function ($method, $url, $options) use (&$calls, &$responses) {
            $calls[] = [$method, $url, $options];
            return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
        });
        $asset = new Asset();
        $asset->originalUrl = 'https://example.org/folder.pdf';
        $task = new PdfOcrTask(new \App\Service\PdfToolsClient($client, 'http://localhost:5001'), new TaskClaimMapper());
        $result = $task->run(new AssetSubject($asset, ['ocr_language' => 'spa']));
        self::assertSame("Existing text\n\nReconocido", $result->meta->response['text']);
        self::assertSame([0, 1], array_column($result->meta->response['pages'], 'index'));
        self::assertSame(['embedded', 'tesseract'], array_column($result->meta->response['pages'], 'textSource'));
        self::assertSame('http://localhost:5001/v1/files/file/pages/2/ocr', $calls[4][1]);
        self::assertSame(['language' => 'spa', 'layout' => false], json_decode($calls[4][2]['body'], true));
        self::assertCount(3, $result->claims);
    }

    public function testServerFailurePropagatesForMessengerRetry(): void
    {
        $client = new MockHttpClient(new MockResponse('{"detail":"busy"}', ['http_code' => 503]));
        $asset = new Asset();
        $asset->originalUrl = 'https://example.org/folder.pdf';
        $task = new PdfOcrTask(new \App\Service\PdfToolsClient($client, 'http://localhost:5001'), new TaskClaimMapper());
        $this->expectException(\Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface::class);
        $task->run(new AssetSubject($asset));
    }
}
