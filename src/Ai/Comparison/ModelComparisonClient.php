<?php

declare(strict_types=1);

namespace App\Ai\Comparison;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Preserves task text and image bytes across chat APIs and native Gemini. */
final class ModelComparisonClient
{
    /** @param array<string, array<string, mixed>> $profiles */
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%media_comparison.models%')]
        private readonly array $profiles,
    ) {
    }

    /** @param list<string> $models */
    public function validate(array $models, bool $requireCredentials = true): void
    {
        if ($models === []) {
            throw new \InvalidArgumentException('Select at least one comparison model.');
        }
        foreach ($models as $model) {
            if (!isset($this->profiles[$model])) {
                throw new \InvalidArgumentException('Unknown model profile: '.$model.'. Available: '.implode(', ', array_keys($this->profiles)));
            }
            if ($requireCredentials && empty($this->profiles[$model]['api_key'])) {
                throw new \InvalidArgumentException('Missing API credential for profile '.$model.'. See config/services/media_comparison.yaml.');
            }
        }
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function request(string $profileName, array $body): array
    {
        $this->validate([$profileName]);
        $profile = $this->profiles[$profileName];
        $body['model'] = $profile['model'];
        $body['max_tokens'] = 4096;
        if (isset($profile['response_format'])) {
            $body['response_format'] = $profile['response_format'];
        }
        if (($profile['provider'] ?? '') !== 'openai') {
            foreach ($body['messages'] as &$message) {
                if (!is_array($message['content'])) {
                    continue;
                }
                foreach ($message['content'] as &$part) {
                    if (($part['type'] ?? '') === 'image_url') {
                        unset($part['image_url']['detail']);
                    }
                }
                unset($part);
            }
            unset($message);
        }
        $record = ['profile' => $profileName, 'provider' => $profile['provider'], 'requested_model' => $profile['model'], 'status' => 'error'];
        try {
            $nativeGemini = ($profile['transport'] ?? '') === 'gemini';
            $options = [
                'json' => $nativeGemini ? $this->geminiRequest($body) : $body,
                'timeout' => 120,
                'max_duration' => 120,
                'max_redirects' => 0,
            ];
            if ($nativeGemini) {
                $options['headers'] = ['x-goog-api-key' => $profile['api_key']];
            } else {
                $options['auth_bearer'] = $profile['api_key'];
            }
            $response = $this->http->request('POST', $profile['endpoint'], $options);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
            $record['http_status'] = $status;
            if ($status >= 400) {
                // Do not copy provider diagnostics: they can echo credentials or image bytes.
                $record['error'] = 'Provider returned HTTP '.$status;
                return $record;
            }
            if ($nativeGemini) {
                $record['native_usage'] = $data['usageMetadata'] ?? [];
                $data = $this->geminiResponse($data);
            }
            $choice = $data['choices'][0] ?? [];
            $text = $choice['message']['content'] ?? null;
            $record['model'] = $data['model'] ?? $profile['model'];
            $record['finish_reason'] = $choice['finish_reason'] ?? null;
            $record['output'] = $text;
            $record['usage'] = $data['usage'] ?? [];
            $record['estimated_cost_usd'] = $this->cost($record['usage'], $profile);
            $record['hypothetical_batch_cost_usd'] = $record['estimated_cost_usd'] === null ? null : $record['estimated_cost_usd'] / 2;
            $record['pricing'] = array_intersect_key($profile, array_flip(['input_per_million', 'cached_input_per_million', 'output_per_million', 'pricing_date']));
            try {
                // Match AbstractPromptTask's accepted Markdown JSON envelope; preserve raw output.
                $json = is_string($text) ? trim($text) : '';
                $json = preg_replace('/^```(?:json)?\s*/i', '', $json) ?? $json;
                $json = preg_replace('/\s*```\s*$/', '', $json) ?? $json;
                $record['markdown_envelope'] = is_string($text) && $json !== trim($text);
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                $record['json_output'] = $decoded;
                $record['status'] = is_array($decoded) && ($choice['finish_reason'] ?? '') === 'stop' ? 'ok' : 'invalid_output';
            } catch (\JsonException) {
                $record['status'] = 'invalid_output';
            }
            return $record;
        } catch (\Exception $e) {
            $record['error'] = 'Request failed ('.$e::class.').';
            return $record;
        }
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function compare(string $profileName, array $body): array
    {
        $start = hrtime(true);
        $result = $this->request($profileName, $body);
        $result['elapsed_ms'] = round((hrtime(true) - $start) / 1_000_000, 2);

        return $result;
    }

    /** @param array<string, mixed> $usage @param array<string, mixed> $profile */
    private function cost(array $usage, array $profile): ?float
    {
        if (!isset($usage['prompt_tokens'], $usage['completion_tokens'])) {
            return null;
        }
        $input = max(0, (int) $usage['prompt_tokens']);
        $output = max(0, (int) $usage['completion_tokens']);
        $cached = min($input, max(0, (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0)));

        return (($input - $cached) * $profile['input_per_million']
            + $cached * $profile['cached_input_per_million']
            + $output * $profile['output_per_million']) / 1_000_000;
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function geminiRequest(array $body): array
    {
        $request = ['contents' => [], 'generationConfig' => ['maxOutputTokens' => $body['max_tokens']]];
        foreach ($body['messages'] as $message) {
            $parts = [];
            $content = is_string($message['content']) ? [['type' => 'text', 'text' => $message['content']]] : $message['content'];
            foreach ($content as $part) {
                if ($part['type'] === 'text') {
                    $parts[] = ['text' => $part['text']];
                } elseif ($part['type'] === 'image_url' && preg_match('#^data:([^;]+);base64,(.+)$#s', $part['image_url']['url'], $match)) {
                    $parts[] = ['inlineData' => ['mimeType' => $match[1], 'data' => $match[2]]];
                } else {
                    throw new \InvalidArgumentException('Native Gemini comparisons require text and inline image parts.');
                }
            }
            if ($message['role'] === 'system') {
                $request['systemInstruction']['parts'] = array_merge($request['systemInstruction']['parts'] ?? [], $parts);
            } else {
                $request['contents'][] = ['role' => $message['role'] === 'assistant' ? 'model' : 'user', 'parts' => $parts];
            }
        }
        if (isset($body['response_format'])) {
            $request['generationConfig']['responseMimeType'] = 'application/json';
            if (isset($body['response_format']['json_schema']['schema'])) {
                $request['generationConfig']['responseJsonSchema'] = $body['response_format']['json_schema']['schema'];
            }
        }

        return $request;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function geminiResponse(array $data): array
    {
        $candidate = $data['candidates'][0] ?? [];
        $text = '';
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (!($part['thought'] ?? false)) {
                $text .= $part['text'] ?? '';
            }
        }
        $usage = $data['usageMetadata'] ?? [];
        $thinking = $usage['thoughtsTokenCount'] ?? 0;

        return [
            'model' => $data['modelVersion'] ?? null,
            'choices' => [['finish_reason' => ($candidate['finishReason'] ?? '') === 'STOP' ? 'stop' : ($candidate['finishReason'] ?? null), 'message' => ['content' => $text]]],
            'usage' => [
                'prompt_tokens' => $usage['promptTokenCount'] ?? null,
                // Native candidatesTokenCount excludes separately billed thinking tokens.
                'completion_tokens' => isset($usage['candidatesTokenCount']) ? $usage['candidatesTokenCount'] + $thinking : null,
                'total_tokens' => $usage['totalTokenCount'] ?? null,
                'completion_tokens_details' => ['reasoning_tokens' => $thinking, 'answer_tokens' => $usage['candidatesTokenCount'] ?? null],
                'prompt_tokens_details' => ['cached_tokens' => $usage['cachedContentTokenCount'] ?? 0],
            ],
        ];
    }
}
