<?php

namespace App\Services\Ai;

use App\Support\Observability\PipelineLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;

class OpenAiCompatibleStructuredOutputClient implements StructuredOutputClientInterface
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

        $maxTokensField = config('services.ai.openai_compatible.max_tokens_field', 'max_tokens');
        if (! in_array($maxTokensField, ['max_tokens', 'max_completion_tokens'], true)) {
            throw new AiProviderException('AI_OPENAI_MAX_TOKENS_FIELD must be max_tokens or max_completion_tokens.');
        }

        $body = [
            'model' => config('services.ai.model'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Return only a valid JSON object matching the requested schema. Do not use Markdown fences.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $temperature,
            $maxTokensField => $maxOutputTokens,
        ];

        $this->addStructuredOutputFormat($body, $operation, $schema);

        PipelineLogger::debug('ai.request_started', [
            'provider' => 'openai-compatible',
            'operation' => $operation,
            'model' => config('services.ai.model'),
            'max_output_tokens' => $maxOutputTokens,
            'timeout_seconds' => $timeoutSeconds,
            'structured_output' => config('services.ai.openai_compatible.structured_output', 'json_schema'),
        ]);

        $startedAt = microtime(true);

        try {
            $requestOptions = [
                'json' => $body,
                'timeout' => $timeoutSeconds,
            ];

            // Many local GPU servers run on a trusted private network and do
            // not use authentication. Do not send a meaningless Bearer header
            // in that mode; hosted APIs continue to receive it when configured.
            if (is_string($apiKey) && $apiKey !== '') {
                $requestOptions['headers'] = ['Authorization' => "Bearer {$apiKey}"];
            }

            $response = $this->client->post(config('services.ai.openai_compatible.path', 'chat/completions'), $requestOptions);
        } catch (GuzzleException $e) {
            PipelineLogger::exception('ai.request_failed', $e, [
                'provider' => 'openai-compatible',
                'operation' => $operation,
                'model' => config('services.ai.model'),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ], 'warning');

            throw new AiProviderException("OpenAI-compatible {$operation} request failed. Check the provider log for the reason.");
        }

        $payload = json_decode((string) $response->getBody(), true);
        $this->logUsage($operation, is_array($payload) ? ($payload['usage'] ?? []) : [], $startedAt);

        $text = data_get($payload, 'choices.0.message.content');
        if (! is_string($text) || $text === '') {
            PipelineLogger::warning('ai.response_invalid', [
                'provider' => 'openai-compatible',
                'operation' => $operation,
                'reason' => 'missing_content',
            ]);

            throw new AiProviderException("OpenAI-compatible {$operation} response had no content.");
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            PipelineLogger::warning('ai.response_invalid', [
                'provider' => 'openai-compatible',
                'operation' => $operation,
                'reason' => 'response_not_json_object',
            ]);

            throw new AiProviderException("OpenAI-compatible {$operation} response was not valid JSON.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $body @param array<string, mixed> $schema */
    private function addStructuredOutputFormat(array &$body, string $operation, array $schema): void
    {
        $format = config('services.ai.openai_compatible.structured_output', 'json_schema');

        if ($format === 'none') {
            return;
        }

        if ($format === 'json_object') {
            $body['response_format'] = ['type' => 'json_object'];

            return;
        }

        if ($format !== 'json_schema') {
            throw new AiProviderException('AI_OPENAI_STRUCTURED_OUTPUT must be json_schema, json_object, or none.');
        }

        $schema['additionalProperties'] ??= false;

        $body['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => Str::of($operation)->replace('-', '_')->append('_response')->toString(),
                'schema' => $schema,
                'strict' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $usage */
    private function logUsage(string $operation, array $usage, float $startedAt): void
    {
        PipelineLogger::info('ai.token_usage', [
            'provider' => 'openai-compatible',
            'model' => config('services.ai.model'),
            'operation' => $operation,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'output_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
