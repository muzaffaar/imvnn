<?php

namespace App\Services\Telegram;

use App\Models\MediaAsset;
use App\Models\TelegramChannel;
use App\Services\Media\Storage\MediaStorageService;
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
        $telegramVariant = $asset->variantOfType(\App\Enums\MediaVariantType::Telegram);
        $path = $telegramVariant?->storage_path ?? $asset->storage_path;

        return $path ? $this->storage->url($path) : $asset->original_url;
    }

    private function call(string $method, array $params): array
    {
        $token = config('services.telegram.bot_token');
        if (! $token) {
            throw new TelegramApiException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        try {
            $response = $this->client->post("bot{$token}/{$method}", ['form_params' => $params]);
        } catch (GuzzleException $e) {
            throw new TelegramApiException("Telegram API transport error calling {$method}: {$e->getMessage()}", previous: $e);
        }

        $body = json_decode((string) $response->getBody(), true) ?? [];

        if (empty($body['ok'])) {
            $description = $body['description'] ?? 'unknown error';
            throw new TelegramApiException("Telegram API rejected {$method}: {$description}");
        }

        return $body['result'] ?? [];
    }
}
