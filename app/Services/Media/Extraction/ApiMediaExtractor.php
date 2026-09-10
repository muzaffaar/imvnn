<?php

namespace App\Services\Media\Extraction;

use App\DTOs\ExtractedMedia;
use App\DTOs\ExtractionContext;
use App\Enums\MediaType;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Extracts media from a structured API response using dot-notation field paths,
 * since every source API shapes its payload differently. Configure per-source
 * field maps in config/media.php (or wherever the source registry lives) and
 * pass the resolved map in via ExtractionContext::$apiPayload['_field_map'],
 * or fall back to the common conventions below.
 */
class ApiMediaExtractor implements MediaExtractorInterface
{
    private const DEFAULT_ARRAY_PATHS = ['images', 'media', 'photos', 'attachments'];

    private const DEFAULT_SINGLE_PATHS = ['image', 'featured_image', 'thumbnail', 'video'];

    public function supports(ExtractionContext $context): bool
    {
        return filled($context->apiPayload);
    }

    public function extract(ExtractionContext $context): Collection
    {
        $payload = $context->apiPayload ?? [];
        $fieldMap = $payload['_field_map'] ?? [];
        $results = collect();
        $position = 0;

        $arrayPaths = $fieldMap['array_paths'] ?? self::DEFAULT_ARRAY_PATHS;
        foreach ($arrayPaths as $path) {
            $items = Arr::get($payload, $path);
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $entry) {
                if ($media = $this->toExtractedMedia($entry, $position)) {
                    $results->push($media);
                    $position++;
                }
            }
        }

        $singlePaths = $fieldMap['single_paths'] ?? self::DEFAULT_SINGLE_PATHS;
        foreach ($singlePaths as $path) {
            $entry = Arr::get($payload, $path);
            if ($media = $this->toExtractedMedia($entry, $position, isFeaturedHint: true)) {
                $results->push($media);
                $position++;
            }
        }

        return $results->unique(fn (ExtractedMedia $m) => $m->fingerprint())->values();
    }

    private function toExtractedMedia(mixed $entry, int $position, bool $isFeaturedHint = false): ?ExtractedMedia
    {
        if (is_string($entry)) {
            $entry = ['url' => $entry];
        }

        if (! is_array($entry) || empty($entry['url'])) {
            return null;
        }

        $type = match ($entry['type'] ?? null) {
            'video' => MediaType::Video,
            'audio' => MediaType::Audio,
            'document' => MediaType::Document,
            'gif' => MediaType::Gif,
            default => MediaType::Image,
        };

        return new ExtractedMedia(
            url: $entry['url'],
            type: $type,
            extractedBy: 'api_payload',
            caption: $entry['caption'] ?? null,
            altText: $entry['alt'] ?? $entry['alt_text'] ?? null,
            width: isset($entry['width']) ? (int) $entry['width'] : null,
            height: isset($entry['height']) ? (int) $entry['height'] : null,
            durationSeconds: isset($entry['duration']) ? (int) $entry['duration'] : null,
            position: $position,
            isFeaturedHint: $isFeaturedHint,
        );
    }
}
