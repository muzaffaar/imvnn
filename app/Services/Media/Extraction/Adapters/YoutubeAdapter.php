<?php

namespace App\Services\Media\Extraction\Adapters;

use App\DTOs\ExtractedMedia;
use App\Enums\MediaType;

class YoutubeAdapter implements PlatformAdapterInterface
{
    public function matches(string $url): bool
    {
        return (bool) $this->extractVideoId($url);
    }

    public function normalize(ExtractedMedia $media): ExtractedMedia
    {
        $videoId = $this->extractVideoId($media->url);
        if (! $videoId) {
            return $media;
        }

        return new ExtractedMedia(
            url: "https://www.youtube.com/watch?v={$videoId}",
            type: MediaType::Video,
            extractedBy: $media->extractedBy,
            caption: $media->caption,
            altText: $media->altText,
            width: $media->width,
            height: $media->height,
            durationSeconds: $media->durationSeconds,
            externalProvider: 'youtube',
            externalId: $videoId,
            thumbnailUrl: "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            position: $media->position,
            isFeaturedHint: $media->isFeaturedHint,
            extra: $media->extra,
        );
    }

    private function extractVideoId(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        if (! str_contains($host, 'youtube.com') && ! str_contains($host, 'youtu.be') && ! str_contains($host, 'youtube-nocookie.com')) {
            return null;
        }

        if (str_contains($host, 'youtu.be')) {
            $id = trim(parse_url($url, PHP_URL_PATH) ?? '', '/');

            return $id !== '' ? $id : null;
        }

        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if (! empty($query['v'])) {
            return $query['v'];
        }

        // /embed/{id} or /v/{id}
        if (preg_match('#/(embed|v|shorts)/([A-Za-z0-9_-]{6,})#', parse_url($url, PHP_URL_PATH) ?? '', $m)) {
            return $m[2];
        }

        return null;
    }
}
