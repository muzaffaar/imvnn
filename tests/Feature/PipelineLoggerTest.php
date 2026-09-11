<?php

namespace Tests\Feature;

use App\Support\Observability\PipelineLogger;
use Tests\TestCase;

class PipelineLoggerTest extends TestCase
{
    public function test_it_removes_query_credentials_and_fragments_from_urls(): void
    {
        $url = PipelineLogger::url('https://example.test/news/article?token=secret-value#heading');

        $this->assertSame('https://example.test/news/article', $url);
    }

    public function test_it_redacts_configured_and_standard_credentials_from_messages(): void
    {
        config()->set('services.ai.api_key', 'provider-secret');
        config()->set('services.telegram.bot_token', '123456:telegram-secret');

        $message = PipelineLogger::sanitize(
            'GET https://api.example.test/v1?x-goog-api-key=provider-secret Bearer provider-secret '
            .'https://api.telegram.org/bot123456:telegram-secret/sendMessage'
        );

        $this->assertStringNotContainsString('provider-secret', $message);
        $this->assertStringNotContainsString('123456:telegram-secret', $message);
        $this->assertStringContainsString('[redacted]', $message);
    }
}
