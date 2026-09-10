<?php

namespace App\Services\Ai;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

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
            throw new AiProviderException("Gemini {$operation} request failed: {$e->getMessage()}", previous: $e);
        }

        $payload = json_decode((string) $response->getBody(), true);
        $this->logUsage($operation, is_array($payload) ? ($payload['usageMetadata'] ?? []) : []);

        $text = data_get($payload, 'candidates.0.content.parts.0.text');
        if (! is_string($text) || $text === '') {
            throw new AiProviderException("Gemini {$operation} response had no content.");
        }

        return $this->decodeJson($operation, $text);
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $operation, string $text): array
    {
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new AiProviderException("Gemini {$operation} response was not valid JSON.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $usage */
    private function logUsage(string $operation, array $usage): void
    {
        Log::info("[ai-{$operation}] token usage", [
            'provider' => 'gemini',
            'prompt_tokens' => $usage['promptTokenCount'] ?? null,
            'output_tokens' => $usage['candidatesTokenCount'] ?? null,
            'total_tokens' => $usage['totalTokenCount'] ?? null,
        ]);
    }
}
