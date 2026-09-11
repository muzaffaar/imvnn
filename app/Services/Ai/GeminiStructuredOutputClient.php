<?php

namespace App\Services\Ai;

use App\Support\Observability\PipelineLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class GeminiStructuredOutputClient implements StructuredOutputClientInterface
{
    public function __construct(private readonly Client $client) {}

    public function generate(
        string $operation,
        string $prompt,
        array $schema,
        int $maxOutputTokens,
        float $temperature,
        int $timeoutSeconds,
    ): array {
        $apiKey = config('services.ai.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            throw new AiProviderException('AI_API_KEY is not configured.');
        }

        PipelineLogger::debug('ai.request_started', [
            'provider' => 'gemini',
            'operation' => $operation,
            'model' => config('services.ai.model'),
            'max_output_tokens' => $maxOutputTokens,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        $startedAt = microtime(true);

        try {
            $response = $this->client->post('v1beta/models/'.config('services.ai.model').':generateContent', [
                'query' => ['key' => $apiKey],
                'json' => [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'maxOutputTokens' => $maxOutputTokens,
                        'temperature' => $temperature,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $schema,
                    ],
                ],
                'timeout' => $timeoutSeconds,
            ]);
        } catch (GuzzleException $e) {
            PipelineLogger::exception('ai.request_failed', $e, [
                'provider' => 'gemini',
                'operation' => $operation,
                'model' => config('services.ai.model'),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ], 'warning');

            throw new AiProviderException("Gemini {$operation} request failed. Check the provider log for the reason.");
        }

        $payload = json_decode((string) $response->getBody(), true);
        $this->logUsage($operation, is_array($payload) ? ($payload['usageMetadata'] ?? []) : [], $startedAt);

        $text = data_get($payload, 'candidates.0.content.parts.0.text');
        if (! is_string($text) || $text === '') {
            PipelineLogger::warning('ai.response_invalid', [
                'provider' => 'gemini',
                'operation' => $operation,
                'reason' => 'missing_content',
            ]);

            throw new AiProviderException("Gemini {$operation} response had no content.");
        }

        return $this->decodeJson($operation, $text);
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $operation, string $text): array
    {
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            PipelineLogger::warning('ai.response_invalid', [
                'provider' => 'gemini',
                'operation' => $operation,
                'reason' => 'response_not_json_object',
            ]);

            throw new AiProviderException("Gemini {$operation} response was not valid JSON.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $usage */
    private function logUsage(string $operation, array $usage, float $startedAt): void
    {
        PipelineLogger::info('ai.token_usage', [
            'provider' => 'gemini',
            'model' => config('services.ai.model'),
            'operation' => $operation,
            'prompt_tokens' => $usage['promptTokenCount'] ?? null,
            'output_tokens' => $usage['candidatesTokenCount'] ?? null,
            'total_tokens' => $usage['totalTokenCount'] ?? null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
