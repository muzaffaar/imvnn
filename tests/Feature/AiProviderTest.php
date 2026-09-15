<?php

namespace Tests\Feature;

use App\Services\Ai\GeminiStructuredOutputClient;
use App\Services\Ai\OpenAiCompatibleStructuredOutputClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    public function test_gemini_provider_uses_native_structured_output_request(): void
    {
        config()->set('services.ai', [
            'api_key' => 'gemini-test-key',
            'model' => 'gemini-test-model',
        ]);

        $history = [];
        $client = $this->mockClient([
            new Response(200, [], json_encode([
                'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 5, 'totalTokenCount' => 17],
                'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
            ])),
        ], $history);

        $result = (new GeminiStructuredOutputClient($client))->generate(
            operation: 'analysis',
            prompt: 'Test prompt',
            schema: ['type' => 'object'],
            maxOutputTokens: 100,
            temperature: 0.1,
            timeoutSeconds: 5,
        );

        $this->assertSame(['ok' => true], $result);
        $request = $history[0]['request'];
        $this->assertSame('/v1beta/models/gemini-test-model:generateContent', $request->getUri()->getPath());
        $this->assertSame('gemini-test-key', $request->getUri()->getQuery() === '' ? null : explode('=', $request->getUri()->getQuery())[1]);
        $this->assertSame('application/json', json_decode((string) $request->getBody(), true)['generationConfig']['responseMimeType']);
    }

    public function test_gemini_provider_attaches_inline_image_data_for_vision_calls(): void
    {
        config()->set('services.ai', [
            'api_key' => 'gemini-test-key',
            'model' => 'gemini-test-model',
        ]);

        $history = [];
        $client = $this->mockClient([
            new Response(200, [], json_encode([
                'candidates' => [['content' => ['parts' => [['text' => '{"relevance_score":80}']]]]],
            ])),
        ], $history);

        (new GeminiStructuredOutputClient($client))->generate(
            operation: 'media-relevance',
            prompt: 'Score this image',
            schema: ['type' => 'object'],
            maxOutputTokens: 20,
            temperature: 0,
            timeoutSeconds: 5,
            images: [['mime_type' => 'image/jpeg', 'data' => 'ZmFrZS1ieXRlcw==']],
        );

        $parts = json_decode((string) $history[0]['request']->getBody(), true)['contents'][0]['parts'];
        $this->assertSame(['mimeType' => 'image/jpeg', 'data' => 'ZmFrZS1ieXRlcw=='], $parts[0]['inlineData']);
        $this->assertSame('Score this image', $parts[1]['text']);
    }

    public function test_openai_compatible_provider_attaches_a_data_uri_image_for_vision_calls(): void
    {
        config()->set('services.ai', [
            'api_key' => 'local-test-key',
            'model' => 'local-model',
            'openai_compatible' => [
                'path' => 'v1/chat/completions',
                'structured_output' => 'json_object',
                'max_tokens_field' => 'max_tokens',
            ],
        ]);

        $history = [];
        $client = $this->mockClient([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => '{"relevance_score":80}']]],
            ])),
        ], $history);

        (new OpenAiCompatibleStructuredOutputClient($client))->generate(
            operation: 'media-relevance',
            prompt: 'Score this image',
            schema: ['type' => 'object'],
            maxOutputTokens: 20,
            temperature: 0,
            timeoutSeconds: 5,
            images: [['mime_type' => 'image/jpeg', 'data' => 'ZmFrZS1ieXRlcw==']],
        );

        $content = json_decode((string) $history[0]['request']->getBody(), true)['messages'][1]['content'];
        $this->assertSame('data:image/jpeg;base64,ZmFrZS1ieXRlcw==', $content[0]['image_url']['url']);
        $this->assertSame('Score this image', $content[1]['text']);
    }

    public function test_openai_compatible_provider_uses_configured_chat_completions_shape(): void
    {
        config()->set('services.ai', [
            'api_key' => 'local-test-key',
            'model' => 'local-model',
            'openai_compatible' => [
                'path' => 'v1/chat/completions',
                'structured_output' => 'json_object',
                'max_tokens_field' => 'max_tokens',
            ],
        ]);

        $history = [];
        $client = $this->mockClient([
            new Response(200, [], json_encode([
                'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 4, 'total_tokens' => 15],
                'choices' => [['message' => ['content' => '{"ok":true}']]],
            ])),
        ], $history);

        $result = (new OpenAiCompatibleStructuredOutputClient($client))->generate(
            operation: 'caption',
            prompt: 'Test prompt',
            schema: ['type' => 'object'],
            maxOutputTokens: 100,
            temperature: 0.7,
            timeoutSeconds: 5,
        );

        $this->assertSame(['ok' => true], $result);
        $request = $history[0]['request'];
        $this->assertSame('/v1/chat/completions', $request->getUri()->getPath());
        $this->assertSame('Bearer local-test-key', $request->getHeaderLine('Authorization'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('local-model', $body['model']);
        $this->assertSame(100, $body['max_tokens']);
        $this->assertSame(['type' => 'json_object'], $body['response_format']);
    }

    public function test_openai_compatible_provider_supports_an_unauthenticated_local_server(): void
    {
        config()->set('services.ai', [
            'api_key' => null,
            'model' => 'local-model',
            'openai_compatible' => [
                'path' => 'v1/chat/completions',
                'structured_output' => 'json_schema',
                'max_tokens_field' => 'max_tokens',
            ],
        ]);

        $history = [];
        $client = $this->mockClient([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => '{"ok":true}']]],
            ])),
        ], $history);

        $result = (new OpenAiCompatibleStructuredOutputClient($client))->generate(
            operation: 'caption',
            prompt: 'Test prompt',
            schema: ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok']],
            maxOutputTokens: 100,
            temperature: 0.7,
            timeoutSeconds: 5,
        );

        $this->assertSame(['ok' => true], $result);
        $request = $history[0]['request'];
        $this->assertSame('', $request->getHeaderLine('Authorization'));
        $this->assertFalse(json_decode((string) $request->getBody(), true)['response_format']['json_schema']['schema']['additionalProperties']);
    }

    /** @param list<Response> $responses @param array<int, array<string, mixed>> $history */
    private function mockClient(array $responses, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client([
            'base_uri' => 'https://provider.test/',
            'handler' => $stack,
        ]);
    }
}
