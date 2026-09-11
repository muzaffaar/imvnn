<?php

namespace Tests\Feature;

use App\Models\TelegramChannel;
use App\Services\Media\Storage\MediaStorageService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramPublisher;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class TelegramPublisherTest extends TestCase
{
    private function publisher(mixed $response): TelegramPublisher
    {
        config(['services.telegram.bot_token' => 'secret-token', 'services.telegram.api_base_uri' => 'https://api.telegram.org']);

        return new TelegramPublisher(new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]), $this->mock(MediaStorageService::class));
    }

    public function test_rate_limit_preserves_retry_delay_without_media_fallback(): void
    {
        $publisher = $this->publisher(new Response(429, [], json_encode(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 123]])));
        try {
            $publisher->sendTextOnly(new TelegramChannel(['chat_id' => '@test']), 'test');
            $this->fail('Expected rejection');
        } catch (TelegramApiException $e) {
            $this->assertSame(123, $e->retryAfter);
            $this->assertFalse($e->mediaRejected);
            $this->assertFalse($e->deliveryUnknown);
        }
    }

    public function test_transport_error_is_uncertain_and_does_not_expose_token(): void
    {
        $publisher = $this->publisher(new ConnectException('secret-token in request URL', new Request('POST', 'https://example.com')));
        try {
            $publisher->sendTextOnly(new TelegramChannel(['chat_id' => '@test']), 'test');
            $this->fail('Expected uncertain outcome');
        } catch (TelegramApiException $e) {
            $this->assertTrue($e->deliveryUnknown);
            $this->assertFalse($e->mediaRejected);
            $this->assertStringNotContainsString('secret-token', (string) $e);
        }
    }

    public function test_missing_delivery_receipt_is_not_success(): void
    {
        $publisher = $this->publisher(new Response(200, [], '{"ok":true,"result":{}}'));
        $this->expectException(TelegramApiException::class);
        $publisher->sendTextOnly(new TelegramChannel(['chat_id' => '@test']), 'test');
    }

    public function test_definite_media_rejection_allows_fallback(): void
    {
        $publisher = $this->publisher(new Response(400, [], '{"ok":false,"error_code":400,"description":"Bad Request: PHOTO_INVALID_DIMENSIONS"}'));
        try {
            $publisher->sendTextOnly(new TelegramChannel(['chat_id' => '@test']), 'test');
            $this->fail('Expected rejection');
        } catch (TelegramApiException $e) {
            $this->assertTrue($e->mediaRejected);
            $this->assertFalse($e->deliveryUnknown);
        }
    }

    public function test_webpage_curl_failed_is_a_media_rejection(): void
    {
        $publisher = $this->publisher(new Response(400, [], json_encode([
            'ok' => false,
            'error_code' => 400,
            'description' => 'Bad Request: failed to send message #4 with the error message "WEBPAGE_CURL_FAILED"',
        ])));

        try {
            $publisher->sendTextOnly(new TelegramChannel(['chat_id' => '@test']), 'test');
            $this->fail('Expected rejection');
        } catch (TelegramApiException $e) {
            $this->assertTrue($e->mediaRejected);
            $this->assertFalse($e->deliveryUnknown);
        }
    }
}
