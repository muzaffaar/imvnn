<?php

namespace App\Services\Media\Extraction;

use App\DTOs\ExtractedMedia;
use App\DTOs\ExtractionContext;
use App\Enums\MediaType;
use Illuminate\Support\Collection;

/**
 * Extracts media from a normalized RSS/Atom item array (as produced by the
 * feed fetcher upstream of this pipeline). Expected shape, all keys optional:
 *
 * [
 *   'media_contents' => [['url' => ..., 'medium' => 'image'|'video', 'width' => .., 'height' => ..]],
 *   'media_thumbnails' => [['url' => ...]],
 *   'enclosures' => [['url' => ..., 'type' => 'image/jpeg']],
 * ]
 */
class RssMediaExtractor implements MediaExtractorInterface
{
    public function supports(ExtractionContext $context): bool
    {
        return filled($context->rssItem);
    }

    public function extract(ExtractionContext $context): Collection
    {
        $item = $context->rssItem ?? [];
        $results = collect();
        $position = 0;

        foreach ($item['media_contents'] ?? [] as $media) {
            if (empty($media['url'])) {
                continue;
            }

            $results->push(new ExtractedMedia(
                url: $media['url'],
                type: $this->mediumToType($media['medium'] ?? null, $media['url']),
                extractedBy: 'rss_media_content',
                width: isset($media['width']) ? (int) $media['width'] : null,
                height: isset($media['height']) ? (int) $media['height'] : null,
                durationSeconds: isset($media['duration']) ? (int) $media['duration'] : null,
                position: $position++,
                isFeaturedHint: (bool) ($media['is_default'] ?? false),
            ));
        }

        foreach ($item['media_thumbnails'] ?? [] as $thumb) {
            if (empty($thumb['url'])) {
                continue;
            }

            $results->push(new ExtractedMedia(
                url: $thumb['url'],
                type: MediaType::Thumbnail,
                extractedBy: 'rss_media_thumbnail',
                width: isset($thumb['width']) ? (int) $thumb['width'] : null,
                height: isset($thumb['height']) ? (int) $thumb['height'] : null,
                position: $position++,
            ));
        }

        foreach ($item['enclosures'] ?? [] as $enclosure) {
            if (empty($enclosure['url'])) {
                continue;
            }

            $results->push(new ExtractedMedia(
                url: $enclosure['url'],
                type: $this->mimeToType($enclosure['type'] ?? null, $enclosure['url']),
                extractedBy: 'rss_enclosure',
                position: $position++,
                isFeaturedHint: true, // an article's single enclosure is conventionally its lead media
            ));
        }

        return $results->unique(fn (ExtractedMedia $m) => $m->fingerprint())->values();
    }

    private function mediumToType(?string $medium, string $url): MediaType
    {
        return match ($medium) {
            'image' => str_ends_with(strtolower($url), '.gif') ? MediaType::Gif : MediaType::Image,
            'video' => MediaType::Video,
            'audio' => MediaType::Audio,
            'document' => MediaType::Document,
            default => $this->guessTypeFromExtension($url),
        };
    }

    private function mimeToType(?string $mime, string $url): MediaType
    {
        if (! $mime) {
            return $this->guessTypeFromExtension($url);
        }

        return match (true) {
            str_starts_with($mime, 'image/gif') => MediaType::Gif,
            str_starts_with($mime, 'image/') => MediaType::Image,
            str_starts_with($mime, 'video/') => MediaType::Video,
            str_starts_with($mime, 'audio/') => MediaType::Audio,
            default => MediaType::Document,
        };
    }

    private function guessTypeFromExtension(string $url): MediaType
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return match ($ext) {
            'gif' => MediaType::Gif,
            'jpg', 'jpeg', 'png', 'webp' => MediaType::Image,
            'mp4', 'webm', 'mov' => MediaType::Video,
            'mp3', 'wav', 'ogg' => MediaType::Audio,
            default => MediaType::Document,
        };
    }
}
