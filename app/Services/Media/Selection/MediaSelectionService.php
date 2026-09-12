<?php

namespace App\Services\Media\Selection;

use App\DTOs\PostMediaPlan;
use App\Enums\MediaProvider;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\PostMediaType;
use App\Models\MediaAsset;
use App\Models\NewsItem;
use App\Models\TelegramChannel;
use App\Services\Media\Deduplication\RenditionKeyBuilder;
use App\Services\Media\ImageDimensionProbe;
use App\Services\Media\ImageFingerprintProbe;
use App\Services\Media\Scoring\ImageRoleClassifier;
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

    /**
     * How many distinct *pictures* get probed before the final ranking —
     * counted per rendition group, not per URL.
     *
     * The distinction is the whole point. Counting URLs, five renditions of one
     * DeepMind figure plus a run of Hugging Face contributor avatars filled the
     * entire budget, so the article's remaining figures were never probed and
     * never published: an album came back half empty from a pool of seventeen
     * candidates. Counting pictures, the budget buys this many different
     * subjects.
     *
     * Every rendition of a chosen picture is still probed, because which
     * rendition to keep can only be decided from real dimensions — a
     * `width-200` thumbnail and a `width-1600` original are indistinguishable
     * until then. PROBE_REQUEST_CAP bounds the resulting request count.
     */
    private const PROBE_CANDIDATES = 12;

    /** Hard ceiling on probe requests per article, whatever the group sizes. */
    private const PROBE_REQUEST_CAP = 24;

    public function __construct(
        private readonly MediaQualityScorer $qualityScorer,
        private readonly TelegramCompatibilityChecker $telegramChecker,
        private readonly RenditionKeyBuilder $renditionKeys,
        private readonly ImageDimensionProbe $dimensionProbe,
        private readonly ImageRoleClassifier $roleClassifier,
        private readonly ImageFingerprintProbe $fingerprintProbe,
    ) {}

    public function selectForNewsItem(NewsItem $newsItem, TelegramChannel $channel): PostMediaPlan
    {
        $pool = $this->gatherPool($newsItem);
        $poolCount = $pool->count();
        $minQuality = $channel->rule('min_quality_score', 0.35);

        $usable = $pool
            ->filter(fn (MediaAsset $asset) => $asset->isUsable())
            ->reject(fn (MediaAsset $asset) => $this->isUnpublishableFormat($asset))
            ->reject(fn (MediaAsset $asset) => $this->isNonContentImage($asset))
            ->map(fn (MediaAsset $asset) => [$asset, $this->contextualScore($asset)])
            ->filter(fn (array $pair) => $pair[1] >= $minQuality)
            ->sortByDesc(fn (array $pair) => $pair[1])
            ->map(fn (array $pair) => $pair[0])
            ->values();
        $qualityEligibleCount = $usable->count();

        // Budget the probe window in distinct pictures rather than URLs, while
        // still handing every rendition of each chosen picture to the probe.
        $distinctCount = $this->renditionGroups($usable)->count();
        $toProbe = $this->takeDistinctPictures($usable);

        // Only now — for the few candidates of an article actually being
        // published — is it worth learning real dimensions, which is what
        // makes the resolution/aspect-ratio/Telegram-limit checks below
        // mean anything for reference-only images.
        $usable = $this->probeAndRerank($toProbe);

        // Both passes again, now that dimensions are real: probing is what
        // reveals a 200x200 avatar whose URL gave nothing away, and a 200x100
        // sliver that only its aspect ratio condemns.
        $usable = $this->dropDuplicateRenditions($usable)
            ->reject(fn (MediaAsset $asset) => $this->isNonContentImage($asset))
            ->filter(fn (MediaAsset $asset) => $this->contextualScore($asset) >= $minQuality)
            ->values();

        // Last and strongest pass. Everything above argues from filenames and
        // dimensions, neither of which can tell that one photograph was
        // published under two unrelated names. Only the pixels can, and this
        // is what keeps one picture out of two album slots.
        $usable = $this->dropPerceptualDuplicates($usable);

        if ($usable->isEmpty()) {
            PipelineLogger::info('media.selection_no_usable_asset', [
                'news_item_id' => $newsItem->id,
                'telegram_channel_id' => $channel->id,
                'candidate_pool_count' => $poolCount,
                'quality_eligible_count' => $qualityEligibleCount,
                'distinct_picture_count' => $distinctCount,
                'reason' => 'no_asset_survived_role_format_dimension_and_quality_checks',
            ]);

            return PostMediaPlan::none();
        }

        PipelineLogger::debug('media.selection_candidates_ready', [
            'news_item_id' => $newsItem->id,
            'telegram_channel_id' => $channel->id,
            'candidate_pool_count' => $poolCount,
            'quality_eligible_count' => $qualityEligibleCount,
            'distinct_picture_count' => $distinctCount,
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
     * don't catch these. Filename evidence only; `dropPerceptualDuplicates`
     * is what settles the cases a filename cannot.
     *
     * @param  Collection<int, MediaAsset>  $ranked
     * @return Collection<int, MediaAsset>
     */
    private function dropDuplicateRenditions(Collection $ranked): Collection
    {
        return $this->renditionGroups($ranked)
            ->map(fn (Collection $group) => $group->first())
            ->values();
    }

    /**
     * Fingerprints the leading candidates, then collapses whatever the pixels
     * reveal to be one picture.
     *
     * Runs last, and only over the finalists, for two reasons. Every cheaper
     * signal has already had its turn, so the list arriving here is short and
     * mostly distinct. And a fingerprint costs a bounded HTTP read, which is
     * only worth spending on an image that can still reach the album.
     *
     * The losing copy is recorded as a resolved duplicate rather than merely
     * skipped. Without that, the same pair is re-fetched and re-compared for
     * every later article citing either URL; with it, `isUsable()` drops the
     * loser before selection even begins, and Level 1/2 detection stops
     * offering it as a canonical.
     *
     * @param  Collection<int, MediaAsset>  $ranked
     * @return Collection<int, MediaAsset>
     */
    private function dropPerceptualDuplicates(Collection $ranked): Collection
    {
        $candidates = $ranked->take((int) config('media.deduplication.fingerprint_candidates', 8));
        $budgetSeconds = (float) config('media.deduplication.fingerprint_budget_seconds', 25);
        $startedAt = microtime(true);
        $attempted = 0;

        foreach ($candidates as $asset) {
            if (microtime(true) - $startedAt >= $budgetSeconds) {
                PipelineLogger::warning('media.fingerprint_budget_exhausted', [
                    'attempted' => $attempted,
                    'unfingerprinted' => $candidates->count() - $attempted,
                    'budget_seconds' => $budgetSeconds,
                ]);

                break;
            }

            $attempted++;

            if ($asset->type->isVisual()) {
                $this->fingerprintProbe->fingerprint($asset);
            }
        }

        // Fingerprinting reads the complete file, which corrects dimensions the
        // 64 KB header probe got wrong — so the ranking that decides which copy
        // of a picture survives has to be recomputed from the corrected values,
        // not inherited from the guess.
        $ranked = $ranked
            ->reject(fn (MediaAsset $asset) => $this->hasUnusableDimensions($asset))
            ->sortByDesc(fn (MediaAsset $asset) => $this->contextualScore($asset))
            ->values();

        $survivors = collect();

        foreach ($this->renditionGroups($ranked) as $group) {
            /** @var MediaAsset $keeper */
            $keeper = $group->first();
            $survivors->push($keeper);

            foreach ($group->skip(1) as $duplicate) {
                $this->recordResolvedDuplicate($duplicate, $keeper);
            }
        }

        return $survivors->values();
    }

    private function recordResolvedDuplicate(MediaAsset $duplicate, MediaAsset $keeper): void
    {
        $matchedOnPixels = (bool) ($duplicate->perceptual_hash && $keeper->perceptual_hash);

        PipelineLogger::info('media.selection_duplicate_dropped', [
            'media_asset_id' => $duplicate->id,
            'duplicate_of_id' => $keeper->id,
            'duplicate_url' => PipelineLogger::url($duplicate->original_url),
            'kept_url' => PipelineLogger::url($keeper->original_url),
            'matched_on' => $matchedOnPixels ? 'perceptual_hash' : 'rendition_key',
        ]);

        // Only a pixel-level match is certain enough to persist. A rendition
        // key is a filename guess: good enough to pick one copy for this post,
        // not good enough to mark a row duplicate for every future one.
        if (! $matchedOnPixels || $duplicate->duplicate_of_id || $duplicate->id === $keeper->id) {
            return;
        }

        // Re-parenting rows that already point at this asset is not this
        // method's call to make, so a canonical is left alone.
        if ($duplicate->duplicates()->exists()) {
            return;
        }

        $duplicate->update([
            'status' => MediaStatus::Duplicate,
            'duplicate_of_id' => $keeper->id,
            'processed_at' => now(),
        ]);
    }

    /**
     * Rank-ordered groups of renditions of the same picture.
     *
     * Grouped with `isSamePictureByKeys` rather than one exact key, because a CMS can
     * truncate one image's filename differently per rendition — so two keys
     * that differ by a character still name one picture. A plain `groupBy` kept
     * both and the album showed it twice.
     *
     * @param  Collection<int, MediaAsset>  $ranked
     * @return Collection<int, Collection<int, MediaAsset>>
     */
    private function renditionGroups(Collection $ranked): Collection
    {
        /** @var list<array{keys: list<string>, assets: Collection<int, MediaAsset>}> $groups */
        $groups = [];

        foreach ($ranked as $asset) {
            $keys = $this->renditionKeys->keysFor($asset);
            $matched = false;

            foreach ($groups as $index => $group) {
                if ($this->renditionKeys->isSamePictureByKeys($group['keys'], $keys)) {
                    $groups[$index]['assets']->push($asset);
                    // A group keeps every key its members contributed, so a
                    // truncation of a truncation still matches the shortest
                    // stem the group has seen, and a perceptual hash learned
                    // from one member becomes a way in for the next.
                    $groups[$index]['keys'] = array_values(array_unique([...$group['keys'], ...$keys]));
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $groups[] = ['keys' => $keys, 'assets' => collect([$asset])];
            }
        }

        return collect($groups)->map(fn (array $group) => $group['assets'])->values();
    }

    /**
     * The renditions belonging to the best PROBE_CANDIDATES distinct pictures,
     * flattened back into one rank-ordered collection and capped at
     * PROBE_REQUEST_CAP members.
     *
     * Groups are taken whole rather than trimmed, so the later
     * probe-then-deduplicate step still gets to compare a picture's
     * `width-200` and `width-1600` renditions on their real dimensions instead
     * of guessing from the URL.
     *
     * @param  Collection<int, MediaAsset>  $ranked
     * @return Collection<int, MediaAsset>
     */
    private function takeDistinctPictures(Collection $ranked): Collection
    {
        return $this->renditionGroups($ranked)
            ->take(self::PROBE_CANDIDATES)
            ->flatten(1)
            ->take(self::PROBE_REQUEST_CAP)
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

    /**
     * Rejects anything that is not a picture of the story — an author avatar, a
     * logo, a tracking pixel, an ad. Run twice around the probe, because the
     * small-square rule that catches an unfamiliar avatar CDN needs real
     * dimensions, and before probing a reference-only asset has none.
     *
     * Non-visual assets (video, embeds) are not classified: the patterns
     * describe still images, and a video URL is judged by VideoDecisionService.
     */
    private function isNonContentImage(MediaAsset $asset): bool
    {
        if (! $asset->type->isVisual()) {
            return false;
        }

        $role = $this->roleClassifier->classify(
            $asset->original_url,
            $asset->alt_text,
            $asset->width,
            $asset->height,
        );

        if ($role->isPublishable()) {
            return false;
        }

        PipelineLogger::debug('media.selection_role_rejected', [
            'media_asset_id' => $asset->id,
            'role' => $role->value,
            'width' => $asset->width,
            'height' => $asset->height,
        ]);

        return true;
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
