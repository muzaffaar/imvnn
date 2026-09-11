<?php

namespace App\Services\Media\Extraction;

use App\DTOs\ExtractedMedia;
use App\DTOs\ExtractionContext;
use App\Services\Media\Extraction\Adapters\PlatformAdapterInterface;
use App\Support\Observability\PipelineLogger;
use Illuminate\Support\Collection;

/**
 * Runs every applicable MediaExtractorInterface against a context, applies
 * platform adapters to the results, and merges duplicate candidates (the same
 * image is routinely found by both og:meta and the <img> scan) into one entry
 * per fingerprint. This is the single entry point ExtractMediaJob calls.
 */
class MediaExtractionManager
{
    /** @param iterable<MediaExtractorInterface> $extractors */
    public function __construct(
        private readonly iterable $extractors,
        /** @var iterable<PlatformAdapterInterface> */
        private readonly iterable $adapters = [],
    ) {}

    /** @return Collection<int, ExtractedMedia> */
    public function extract(ExtractionContext $context): Collection
    {
        $raw = collect();

        foreach ($this->extractors as $extractor) {
            if (! $extractor->supports($context)) {
                continue;
            }

            try {
                $raw = $raw->concat($extractor->extract($context));
            } catch (\Throwable $e) {
                PipelineLogger::exception('media.extractor_failed', $e, [
                    'news_item_id' => $context->newsItemId,
                    'extractor' => $extractor::class,
                ], 'warning');
            }
        }

        $normalized = $raw->map(fn (ExtractedMedia $media) => $this->applyAdapters($media));

        return $this->mergeDuplicates($normalized);
    }

    private function applyAdapters(ExtractedMedia $media): ExtractedMedia
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->matches($media->url)) {
                return $adapter->normalize($media);
            }
        }

        return $media;
    }

    /** @param Collection<int, ExtractedMedia> $items */
    private function mergeDuplicates(Collection $items): Collection
    {
        return $items
            ->groupBy(fn (ExtractedMedia $m) => $m->fingerprint())
            ->map(function (Collection $group) {
                return $group->reduce(function (?ExtractedMedia $carry, ExtractedMedia $item) {
                    if (! $carry) {
                        return $item;
                    }

                    return new ExtractedMedia(
                        url: $carry->url,
                        type: $carry->type,
                        extractedBy: $carry->extractedBy,
                        caption: $carry->caption ?? $item->caption,
                        altText: $carry->altText ?? $item->altText,
                        width: $carry->width ?? $item->width,
                        height: $carry->height ?? $item->height,
                        durationSeconds: $carry->durationSeconds ?? $item->durationSeconds,
                        externalProvider: $carry->externalProvider ?? $item->externalProvider,
                        externalId: $carry->externalId ?? $item->externalId,
                        thumbnailUrl: $carry->thumbnailUrl ?? $item->thumbnailUrl,
                        position: min($carry->position, $item->position),
                        isFeaturedHint: $carry->isFeaturedHint || $item->isFeaturedHint,
                        extra: array_merge($item->extra, $carry->extra),
                    );
                });
            })
            ->values()
            ->sortBy(fn (ExtractedMedia $m) => $m->position)
            ->values();
    }
}
