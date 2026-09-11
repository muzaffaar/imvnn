<?php

namespace App\Services\Telegram;

use App\Enums\MediaVariantType;
use App\Models\MediaAsset;
use App\Models\TelegramChannel;
use App\Services\Media\Storage\MediaStorageService;
use App\Support\Observability\PipelineLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Thin, deliberately dumb wrapper around the Telegram Bot API HTTP methods.
 * Fallback ladder logic (media group -> single image -> text-only) lives in
 * PublishToTelegramJob, not here — this class just sends one request shape
 * and turns non-2xx / ok:false responses into TelegramApiException.
 */
class TelegramPublisher
{
    public function __construct(
        private readonly Client $client,
        private readonly MediaStorageService $storage,
    ) {}

    public function sendTextOnly(TelegramChannel $channel, string $text): array
    {
        return $this->call('sendMessage', [
            'chat_id' => $channel->chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ]);
    }

    public function sendSinglePhoto(TelegramChannel $channel, MediaAsset $asset, string $caption): array
    {
        return $this->call('sendPhoto', [
            'chat_id' => $channel->chat_id,
            'photo' => $this->publicUrlFor($asset),
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ]);
    }

    public function sendVideo(TelegramChannel $channel, MediaAsset $asset, string $caption): array
    {
        return $this->call('sendVideo', [
            'chat_id' => $channel->chat_id,
            'video' => $this->publicUrlFor($asset),
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'supports_streaming' => true,
        ]);
    }

    /** @param list<MediaAsset> $assets */
    public function sendMediaGroup(TelegramChannel $channel, array $assets, string $caption): array
    {
        $media = [];
        foreach ($assets as $i => $asset) {
            $item = [
                'type' => 'photo',
                'media' => $this->publicUrlFor($asset),
            ];
            if ($i === 0) {
                $item['caption'] = $caption;
                $item['parse_mode'] = 'HTML';
            }
            $media[] = $item;
        }

        return $this->call('sendMediaGroup', [
            'chat_id' => $channel->chat_id,
            'media' => json_encode($media),
        ]);
    }

    /** Prefer the 'telegram' variant if one has already been generated; fall back to the original. */
    private function publicUrlFor(MediaAsset $asset): string
    {
        $telegramVariant = $asset->variantOfType(MediaVariantType::Telegram);
        $path = $telegramVariant?->storage_path ?? $asset->storage_path;

        return $path ? $this->storage->url($path) : $asset->original_url;
    }

    private function call(string $method, array $params): array
    {
        $token = config('services.telegram.bot_token');
        if (! $token) {
            throw new TelegramApiException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        // Built as a full absolute URL rather than a relative path resolved
        // against the client's base_uri: a bot token contains a colon
        // (botNNN:AAFT...), and Guzzle/PSR-7 URI resolution treats a colon in
        // a relative reference's first path segment as a scheme delimiter —
        // "bot123:AAFT.../sendMessage" parses as scheme "bot123", not a path,
        // failing with "The scheme 'bot123' is not supported." (RFC 3986 §4.2).
        $url = rtrim(config('services.telegram.api_base_uri'), '/')."/bot{$token}/{$method}";

        try {
            $response = $this->client->post($url, ['form_params' => $params, 'http_errors' => false]);
        } catch (GuzzleException $e) {
            // Exception URLs contain the bot token. Never persist the original
            // exception or its message in queue failures/logs.
            PipelineLogger::exception('telegram.api_transport_failed', $e, ['method' => $method], 'warning');

            throw new TelegramApiException("Telegram delivery outcome unknown calling {$method}.", deliveryUnknown: true);
        }

        $body = json_decode((string) $response->getBody(), true) ?? [];

        if ($response->getStatusCode() >= 500 || ! is_array($body) || ! array_key_exists('ok', $body)) {
            PipelineLogger::warning('telegram.api_delivery_unknown', [
                'method' => $method,
                'http_status' => $response->getStatusCode(),
                'response_shape_valid' => is_array($body) && array_key_exists('ok', $body),
            ]);

            throw new TelegramApiException("Telegram delivery outcome unknown calling {$method}.", deliveryUnknown: true);
        }

        if ($body['ok'] !== true) {
            $description = $body['description'] ?? 'unknown error';
            $code = (int) ($body['error_code'] ?? $response->getStatusCode());
            PipelineLogger::warning('telegram.api_rejected', [
                'method' => $method,
                'http_status' => $response->getStatusCode(),
                'api_error_code' => $code,
                'reason' => (string) $description,
            ]);

            throw new TelegramApiException(
                "Telegram API rejected {$method}: ".str_replace($token, '[redacted]', $description),
                retryAfter: $code === 429 ? max(1, (int) ($body['parameters']['retry_after'] ?? 60)) : null,
                mediaRejected: $code === 400 && $this->isMediaRejection((string) $description),
            );
        }

        $result = $body['result'] ?? null;
        $messages = $method === 'sendMediaGroup' ? $result : [$result];
        if (! is_array($messages) || $messages === [] ||
            ($method === 'sendMediaGroup' && count($messages) !== count(json_decode($params['media'], true)))) {
            throw new TelegramApiException('Telegram returned an incomplete delivery receipt.', deliveryUnknown: true);
        }
        foreach ($messages as $message) {
            if (! is_array($message) || empty($message['message_id'])) {
                throw new TelegramApiException('Telegram returned an invalid delivery receipt.', deliveryUnknown: true);
            }
        }

        PipelineLogger::debug('telegram.api_accepted', [
            'method' => $method,
            'message_count' => count($messages),
        ]);

        return $result;
    }

    /**
     * Telegram uses both stable error tokens and prose for failures fetching
     * remote media. Keep this narrow: a generic HTTP 400 (bad chat, markup,
     * permissions, etc.) must still fail/retry rather than skip media.
     */
    private function isMediaRejection(string $description): bool
    {
        $normalized = strtoupper($description);

        if (preg_match('/\b(?:WEBPAGE_CURL_FAILED|WEBPAGE_MEDIA_EMPTY|(?:PHOTO|VIDEO|IMAGE|FILE|MEDIA)_[A-Z0-9_]+)\b/', $normalized)) {
            return true;
        }

        return (bool) preg_match(
            '/\b(?:FAILED TO (?:GET|FETCH|DOWNLOAD)|WRONG|INVALID|EMPTY|UNAVAILABLE)\b.*\b(?:HTTP(?:S)? URL|HTTP URL CONTENT|FILE IDENTIFIER)\b|\b(?:HTTP(?:S)? URL|HTTP URL CONTENT|FILE IDENTIFIER)\b.*\b(?:FAILED|INVALID|EMPTY|UNAVAILABLE)\b/',
            $normalized,
        );
    }
}
