<?php

namespace App\Services\Media\Selection;

use App\DTOs\PostMediaPlan;
use App\Enums\MediaProvider;
use App\Enums\MediaType;
use App\Enums\PostMediaType;
use App\Models\MediaAsset;
use App\Models\NewsItem;
use App\Models\TelegramChannel;
use App\Services\Media\Deduplication\RenditionKeyBuilder;
use App\Services\Media\ImageDimensionProbe;
use App\Services\Media\Scoring\MediaQualityScorer;
use App\Services\Media\Scoring\TelegramCompatibilityChecker;
use App\Support\Observability\PipelineLogger;
use Illuminate\Support\Collection;

/**
 * Picks what to actually publish for a news item, per docs/MEDIA_ARCHITECTURE.md
 * "Telegram media selection". Pulls from the news item's own media AND — when
 * it belongs to an Event — the event's shared media pool, so an official
 * product photo from one article can illustrate a post generated from another.
 */
class MediaSelectionService
{
    /**
     * Telegram's sendPhoto/sendMediaGroup accept JPEG/PNG/GIF/WEBP — an SVG
     * URL is simply rejected, so posting one only burns a step of the
     * fallback ladder. In practice SVGs on news pages are also almost always
     * site chrome rather than article art (confirmed on research.google,
     * whose every article carries ~25 `*_nav.svg` menu icons that otherwise
     * score high enough to be picked).
     */
    private const UNPUBLISHABLE_EXTENSIONS = ['svg', 'svgz', 'ico', 'bmp', 'tif', 'tiff'];

    /** How many top candidates get their dimensions probed before the final ranking. */
    private const PROBE_CANDIDATES = 8;

    public function __construct(
        private readonly MediaQualityScorer $qualityScorer,
        private readonly TelegramCompatibilityChecker $telegramChecker,
        private readonly RenditionKeyBuilder $renditionKeys,
        private readonly ImageDimensionProbe $dimensionProbe,
    ) {}

    public function selectForNewsItem(NewsItem $newsItem, TelegramChannel $channel): PostMediaPlan
    {
        $pool = $this->gatherPool($newsItem);
        $poolCount = $pool->count();
        $minQuality = $channel->rule('min_quality_score', 0.35);

        $usable = $pool
            ->filter(fn (MediaAsset $asset) => $asset->isUsable())
            ->reject(fn (MediaAsset $asset) => $this->isUnpublishableFormat($asset))
            ->map(fn (MediaAsset $asset) => [$asset, $this->contextualScore($asset)])
            ->filter(fn (array $pair) => $pair[1] >= $minQuality)
            ->sortByDesc(fn (array $pair) => $pair[1])
            ->map(fn (array $pair) => $pair[0])
            ->values();
        $qualityEligibleCount = $usable->count();

        // After ranking, so each surviving picture is represented by its
        // best-scoring rendition rather than an arbitrary one.

        // Only now — for the few candidates of an article actually being
        // published — is it worth learning real dimensions, which is what
        // makes the resolution/aspect-ratio/Telegram-limit checks below
        // mean anything for reference-only images.
        $usable = $this->probeAndRerank($usable->take(self::PROBE_CANDIDATES));
        $usable = $this->dropDuplicateRenditions($usable)
            ->filter(fn (MediaAsset $asset) => $this->contextualScore($asset) >= $minQuality)
            ->values();

        if ($usable->isEmpty()) {
            PipelineLogger::info('media.selection_no_usable_asset', [
                'news_item_id' => $newsItem->id,
                'telegram_channel_id' => $channel->id,
                'candidate_pool_count' => $poolCount,
                'quality_eligible_count' => $qualityEligibleCount,
                'reason' => 'no_asset_survived_format_dimension_and_quality_checks',
            ]);

            return PostMediaPlan::none();
        }

        PipelineLogger::debug('media.selection_candidates_ready', [
            'news_item_id' => $newsItem->id,
            'telegram_channel_id' => $channel->id,
            'candidate_pool_count' => $poolCount,
            'quality_eligible_count' => $qualityEligibleCount,
            'final_usable_count' => $usable->count(),
        ]);

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
                if (! $pool->has($asset->id)) {
                    $pool->put($asset->id, $asset);
                }
            }
        }

        return $pool->map(fn (MediaAsset $a) => $a->canonical())->unique('id')->values();
    }

    /**
     * Ranks by quality recomputed against *this* article's relevance rather
     * than the asset's cached cross-article best. Without this, an image
     * merely linked from a "related posts" sidebar — which is the featured
     * image of its own article, and so carries a high cached relevance —
     * outranks the article's actual figures (observed on research.google:
     * a methane-mapping hero image ranked first on a glucose-monitoring
     * article, cached relevance 0.78 vs. this-article relevance 0.05).
     */
    private function contextualScore(MediaAsset $asset): float
    {
        $contextualRelevance = $asset->pivot?->relevance_score;

        if ($contextualRelevance === null) {
            return $this->qualityScorer->score($asset);
        }

        return $this->qualityScorer->score($asset, (float) $contextualRelevance);
    }

    /**
     * One picture, one slot. Assets are already sorted best-first, so
     * `unique()` keeps the highest-scoring rendition of each image and drops
     * the rest — see RenditionKeyBuilder for why URL/content hashes alone
     * don't catch these.
     *
     * @param  Collection<int, MediaAsset>  $ranked
     * @return Collection<int, MediaAsset>
     */
    private function dropDuplicateRenditions(Collection $ranked): Collection
    {
        return $ranked
            ->unique(fn (MediaAsset $asset) => $this->renditionKeys->keyFor($asset))
            ->values();
    }

    /**
     * Probes real dimensions, then drops what those dimensions reveal to be
     * unpublishable and re-ranks on the now-meaningful scores. Everything
     * here is invisible until dimensions are known, which is why it can't
     * run earlier:
     *
     *  - tiny images (icons, tracking pixels, thumbnails masquerading as art)
     *  - extreme aspect ratios: banner ads and sliced-up layout strips, which
     *    also render as unreadable slivers in a Telegram album
     *  - anything Telegram itself would reject (width+height > 10000, or a
     *    ratio outside its accepted range), which would otherwise burn a
     *    step of the publishing fallback ladder on a guaranteed failure
     *
     * @param  Collection<int, MediaAsset>  $candidates
     * @return Collection<int, MediaAsset>
     */
    private function probeAndRerank(Collection $candidates): Collection
    {
        foreach ($candidates as $asset) {
            if ($asset->type->isVisual()) {
                $this->dimensionProbe->probe($asset);
            }
        }

        return $candidates
            ->reject(fn (MediaAsset $asset) => $this->hasUnusableDimensions($asset))
            ->sortByDesc(fn (MediaAsset $asset) => $this->contextualScore($asset))
            ->values();
    }

    private function hasUnusableDimensions(MediaAsset $asset): bool
    {
        if (! $asset->width || ! $asset->height) {
            return false; // still unknown — judge on other signals rather than guessing
        }

        if ($asset->width < config('media.limits.min_image_width')
            || $asset->height < config('media.limits.min_image_height')) {
            return true;
        }

        $ratio = $asset->width / max(1, $asset->height);
        $minRatio = config('media.telegram.photo_min_aspect_ratio');
        $maxRatio = config('media.telegram.photo_max_aspect_ratio');

        if ($ratio < $minRatio || $ratio > $maxRatio) {
            return true;
        }

        // Anything past a banner-like ratio reads as a layout strip rather
        // than a picture, well before Telegram's own hard limit.
        if ($ratio > 4.0 || $ratio < 0.25) {
            return true;
        }

        return ($asset->width + $asset->height) > config('media.telegram.photo_max_dimension_sum');
    }

    private function isUnpublishableFormat(MediaAsset $asset): bool
    {
        $extension = strtolower(
            $asset->file_extension
            ?: pathinfo(parse_url($asset->original_url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION)
        );

        return in_array($extension, self::UNPUBLISHABLE_EXTENSIONS, true);
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
        $thumbnailOrImage = $usable->first(fn (MediaAsset $a) => $a->id !== $video->id && $a->type->isVisual());

        if (! $thumbnailOrImage) {
            return PostMediaPlan::none();
        }

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
