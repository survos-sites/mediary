<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\Comparison\ModelComparisonClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ModelComparisonClientTest extends TestCase
{
    public function testProfileCanEnforceJsonMode(): void
    {
        $profiles = $this->profiles();
        $profiles['gemini']['response_format'] = ['type' => 'json_object'];
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['type' => 'json_object'], $body['response_format']);
            return new MockResponse('{"choices":[{"finish_reason":"stop","message":{"content":"{\"prose\":\"A photo.\"}"}}]}');
        });
        $result = (new ModelComparisonClient($http, $profiles))->compare('gemini', ['messages' => []]);
        self::assertSame('ok', $result['status']);
    }

    public function testNativeGeminiIncludesThinkingInBilledOutput(): void
    {
        $profiles = $this->profiles();
        $profiles['gemini']['transport'] = 'gemini';
        $profiles['gemini']['response_format'] = ['type' => 'json_object'];
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $sent = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('Observe.', $sent['systemInstruction']['parts'][0]['text']);
            self::assertSame('application/json', $sent['generationConfig']['responseMimeType']);
            self::assertSame(['mimeType' => 'image/png', 'data' => 'YQ=='], $sent['contents'][0]['parts'][0]['inlineData']);
            return new MockResponse(json_encode([
                'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
                    ['thought' => true, 'text' => 'Excluded reasoning'],
                    ['text' => '{"prose":"A photo."}', 'thoughtSignature' => 'opaque'],
                ]]]],
                'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 370, 'totalTokenCount' => 1380],
            ], JSON_THROW_ON_ERROR));
        });
        $result = (new ModelComparisonClient($http, $profiles))->compare('gemini', ['messages' => [
            ['role' => 'system', 'content' => 'Observe.'],
            ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,YQ==']]]],
        ]]);
        self::assertSame('ok', $result['status']);
        self::assertSame(380, $result['usage']['completion_tokens']);
        self::assertSame(370, $result['native_usage']['thoughtsTokenCount']);
        self::assertSame(['prose' => 'A photo.'], $result['json_output']);
        self::assertEqualsWithDelta(.000252, $result['estimated_cost_usd'], 1e-10);
    }

    public function testGeminiKeepsPromptAndBytesButRemovesOpenAiDetailAndAccountsForCache(): void
    {
        $body = ['messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Observe this image.'],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,YQ==', 'detail' => 'low']],
        ]]]];
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            $sent = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('gemini-2.5-flash-lite', $sent['model']);
            self::assertSame('Observe this image.', $sent['messages'][0]['content'][0]['text']);
            self::assertSame(['url' => 'data:image/png;base64,YQ=='], $sent['messages'][0]['content'][1]['image_url']);
            return new MockResponse(json_encode([
                'model' => 'gemini-2.5-flash-lite',
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"prose":"A photo."}']]],
                'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500, 'prompt_tokens_details' => ['cached_tokens' => 100]],
            ], JSON_THROW_ON_ERROR));
        });
        $result = (new ModelComparisonClient($http, $this->profiles()))->compare('gemini', $body);
        self::assertSame('ok', $result['status']);
        self::assertSame(['prose' => 'A photo.'], $result['json_output']);
        self::assertEqualsWithDelta(0.000291, $result['estimated_cost_usd'], 1e-10);
        self::assertEqualsWithDelta(0.0001455, $result['hypothetical_batch_cost_usd'], 1e-10);
        self::assertGreaterThanOrEqual(0, $result['elapsed_ms']);
        self::assertSame('low', $body['messages'][0]['content'][1]['image_url']['detail']);
    }

    public function testErrorsDoNotLeakProviderBodyAndNextCallStillRuns(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{"error":{"message":"secret-token image-data"}}', ['http_code' => 403]),
            new MockResponse('{"choices":[{"finish_reason":"length","message":{"content":"{}"}}]}'),
        ]);
        $client = new ModelComparisonClient($http, $this->profiles());
        $first = $client->compare('gemini', ['messages' => []]);
        self::assertSame('error', $first['status']);
        self::assertStringNotContainsString('secret-token', json_encode($first));
        $second = $client->compare('gemini', ['messages' => []]);
        self::assertSame('invalid_output', $second['status']);
        self::assertNull($second['estimated_cost_usd']);
    }

    public function testMissingCredentialFailsBeforeHttp(): void
    {
        $profiles = $this->profiles();
        $profiles['gemini']['api_key'] = null;
        $client = new ModelComparisonClient(new MockHttpClient(), $profiles);
        $client->validate(['gemini'], false); // Dry runs need no credentials.
        $this->expectException(\InvalidArgumentException::class);
        $client->validate(['gemini']);
    }

    public function testFencedJsonMatchesTaskParserAndPreservesRawOutput(): void
    {
        $text = "```json\n{\"prose\":\"A photograph.\"}\n```";
        $http = new MockHttpClient(new MockResponse(json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $text]]]], JSON_THROW_ON_ERROR)));
        $result = (new ModelComparisonClient($http, $this->profiles()))->compare('gemini', ['messages' => []]);
        self::assertSame('ok', $result['status']);
        self::assertTrue($result['markdown_envelope']);
        self::assertSame($text, $result['output']);
        self::assertSame(['prose' => 'A photograph.'], $result['json_output']);
    }

    private function profiles(): array
    {
        return ['gemini' => ['provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'api_key' => 'secret-token', 'endpoint' => 'https://example.test/chat/completions', 'input_per_million' => .1, 'cached_input_per_million' => .01, 'output_per_million' => .4, 'pricing_date' => '2026-10-06']];
    }
}
