<?php

namespace App\Services\Media\Selection;

use App\DTOs\PostMediaPlan;
use App\Enums\MediaProvider;
use App\Enums\MediaType;
use App\Enums\PostMediaType;
use App\Models\MediaAsset;
use App\Models\NewsItem;
use App\Models\TelegramChannel;
use App\Services\Media\Scoring\MediaQualityScorer;
use App\Services\Media\Scoring\TelegramCompatibilityChecker;
use Illuminate\Support\Collection;

/**
 * Picks what to actually publish for a news item, per docs/MEDIA_ARCHITECTURE.md
 * "Telegram media selection". Pulls from the news item's own media AND — when
 * it belongs to an Event — the event's shared media pool, so an official
 * product photo from one article can illustrate a post generated from another.
 */
class MediaSelectionService
{
    public function __construct(
        private readonly MediaQualityScorer $qualityScorer,
        private readonly TelegramCompatibilityChecker $telegramChecker,
    ) {}

    public function selectForNewsItem(NewsItem $newsItem, TelegramChannel $channel): PostMediaPlan
    {
        $pool = $this->gatherPool($newsItem);
        $minQuality = $channel->rule('min_quality_score', 0.35);

        $usable = $pool
            ->filter(fn (MediaAsset $asset) => $asset->isUsable())
            ->map(fn (MediaAsset $asset) => [$asset, $asset->quality_score ?? $this->qualityScorer->score($asset)])
            ->filter(fn (array $pair) => $pair[1] >= $minQuality)
            ->sortByDesc(fn (array $pair) => $pair[1])
            ->map(fn (array $pair) => $pair[0])
            ->values();

        if ($usable->isEmpty()) {
            return PostMediaPlan::none();
        }

        $video = $usable->first(fn (MediaAsset $a) => $a->type === MediaType::Video);
        $preferVideo = $channel->rule('prefer_video', true);

        if ($video && $preferVideo) {
            return $this->planForVideo($video, $usable);
        }

        return $this->planForImages($usable, $channel);
    }

    /** @return Collection<int, MediaAsset> */
    private function gatherPool(NewsItem $newsItem): Collection
    {
        $pool = $newsItem->mediaAssets()->get()->keyBy('id');

        if ($newsItem->event_id) {
            foreach ($newsItem->event->mediaAssets as $asset) {
                $pool->put($asset->id, $asset);
            }
        }

        return $pool->map(fn (MediaAsset $a) => $a->canonical())->unique('id')->values();
    }

    private function planForVideo(MediaAsset $video, Collection $usable): PostMediaPlan
    {
        $canSendNatively = $video->provider === MediaProvider::Downloaded
            && $this->telegramChecker->check($video)['compatible'];

        if ($canSendNatively) {
            return new PostMediaPlan(PostMediaType::Video, [$video]);
        }

        // Reference-only or too-large video: fall back to its thumbnail (or the
        // best remaining image) as a SingleImage post, with the original video
        // linked in the caption instead of uploaded.
        $thumbnailOrImage = $usable->first(fn (MediaAsset $a) => $a->id !== $video->id && $a->type->isVisual())
            ?? $video;

        return new PostMediaPlan(
            PostMediaType::VideoThumbnailFallback,
            [$thumbnailOrImage],
            fallbackLinkUrl: $video->original_url,
        );
    }

    private function planForImages(Collection $usable, TelegramChannel $channel): PostMediaPlan
    {
        $images = $usable->filter(fn (MediaAsset $a) => $a->type->isVisual())->values();

        if ($images->isEmpty()) {
            return PostMediaPlan::none();
        }

        if ($images->count() === 1) {
            return new PostMediaPlan(PostMediaType::SingleImage, [$images->first()]);
        }

        $maxGroupSize = min(
            (int) $channel->rule('max_images', 4),
            config('media.telegram.max_media_group_items'),
        );

        $allowGroup = $channel->rule('allow_media_group', true) && $maxGroupSize >= 2;

        if (! $allowGroup) {
            return new PostMediaPlan(PostMediaType::SingleImage, [$images->first()]);
        }

        return new PostMediaPlan(PostMediaType::MediaGroup, $images->take($maxGroupSize)->all());
    }
}
