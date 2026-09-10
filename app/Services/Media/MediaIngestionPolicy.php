<?php

namespace App\Services\Media;

use App\DTOs\ExtractedMedia;
use App\Enums\MediaIngestionAction;
use App\Enums\MediaType;
use App\Models\Source;

/**
 * Decides, per extracted candidate, whether we ignore it, only ever reference
 * the original URL, or copy the bytes into our own object storage. See
 * docs/MEDIA_ARCHITECTURE.md "Ingestion decision" for the full rationale table.
 *
 * Video is deliberately NOT finalized here: every video starts as Reference and
 * is only ever upgraded to Download by VideoDecisionService, which needs a
 * HEAD/probe response (file size, codec) this policy doesn't have yet.
 */
class MediaIngestionPolicy
{
    public function decide(ExtractedMedia $media, ?Source $source = null): MediaIngestionAction
    {
        if ($media->type === MediaType::Embed) {
            return MediaIngestionAction::Reference;
        }

        if ($media->type === MediaType::Video || $media->type === MediaType::Audio) {
            return MediaIngestionAction::Reference;
        }

        if ($media->externalProvider && in_array($media->externalProvider, config('media.video.reference_only_providers', []), true)) {
            return MediaIngestionAction::Reference;
        }

        if ($this->belowMinimumDimensions($media)) {
            return MediaIngestionAction::Ignore;
        }

        // Conservative default: without an explicit reuse permission from the
        // source, never copy bytes for anything but our own thumbnails — a real
        // deployment should get per-source guidance from legal/licensing here,
        // not just this heuristic.
        if ($source && ! $source->media_reuse_permitted && $media->type !== MediaType::Thumbnail) {
            return MediaIngestionAction::Reference;
        }

        return match ($media->type) {
            MediaType::Image, MediaType::Gif, MediaType::Infographic, MediaType::Chart, MediaType::Thumbnail => MediaIngestionAction::Download,
            MediaType::Document => MediaIngestionAction::Download,
            default => MediaIngestionAction::Reference,
        };
    }

    private function belowMinimumDimensions(ExtractedMedia $media): bool
    {
        if (! $media->type->isVisual() || $media->type === MediaType::Thumbnail) {
            return false;
        }

        if ($media->width === null || $media->height === null) {
            return false; // unknown until downloaded/probed — don't reject blind
        }

        return $media->width < config('media.limits.min_image_width')
            || $media->height < config('media.limits.min_image_height');
    }
}
