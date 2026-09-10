<?php

namespace App\Services\Media\Video;

use App\Models\MediaAsset;
use App\Models\Source;

/**
 * Decides Reference vs Download vs thumbnail-only vs (eventual) transcode for
 * a video candidate. See docs/MEDIA_ARCHITECTURE.md "Video download decision"
 * for the full rationale. Deliberately conservative: downloading full video
 * files is the most expensive and legally riskiest thing this pipeline does,
 * so every "download" branch requires an affirmative reason, not just the
 * absence of a blocker.
 */
class VideoDecisionService
{
    /**
     * @param  int|null  $probedSizeBytes  from a HEAD request; null if unknown/unprobeable
     */
    public function decide(MediaAsset $media, ?int $probedSizeBytes, ?Source $source): VideoIngestionPlan
    {
        $referenceOnlyProviders = config('media.video.reference_only_providers', []);

        if ($media->external_provider && in_array($media->external_provider, $referenceOnlyProviders, true)) {
            return new VideoIngestionPlan(
                shouldDownload: false,
                shouldFetchThumbnail: true, // e.g. YouTube's i.ytimg.com thumbnail — cheap, no ToS concern
                shouldConsiderTranscode: false,
                reason: "reference-only platform ({$media->external_provider}): bandwidth, ToS, and copyright risk of re-hosting outweigh the benefit",
            );
        }

        if ($source && ! $source->media_reuse_permitted) {
            return new VideoIngestionPlan(
                shouldDownload: false,
                shouldFetchThumbnail: true,
                shouldConsiderTranscode: false,
                reason: 'source has not granted media reuse permission',
            );
        }

        if ($probedSizeBytes === null) {
            return new VideoIngestionPlan(
                shouldDownload: false,
                shouldFetchThumbnail: true,
                shouldConsiderTranscode: false,
                reason: 'could not probe file size before committing to a download',
            );
        }

        if ($probedSizeBytes > config('media.limits.max_video_download_bytes')) {
            return new VideoIngestionPlan(
                shouldDownload: false,
                shouldFetchThumbnail: true,
                shouldConsiderTranscode: false,
                reason: 'exceeds max_video_download_bytes — reference the source URL instead of paying for storage/bandwidth on a file this large',
            );
        }

        // First-party/official source, known size within budget, reuse permitted:
        // worth owning the bytes so we can guarantee availability and generate a
        // Telegram-native upload instead of depending on the source staying up.
        return new VideoIngestionPlan(
            shouldDownload: true,
            shouldFetchThumbnail: true,
            shouldConsiderTranscode: true,
            reason: 'first-party/reuse-permitted source within size budget',
        );
    }
}
