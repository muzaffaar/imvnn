<?php

namespace App\Services\Media\Selection;

use App\Enums\MediaStatus;
use App\Models\MediaAsset;
use App\Models\NewsItem;
use App\Services\Ai\StructuredOutputClientInterface;
use App\Services\Http\BoundedHttpFetcher;
use App\Services\Media\Storage\MediaStorageService;
use App\Support\Observability\PipelineLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * The mandatory final arbiter of what reaches one post's album.
 *
 * Levels 1-3 (URL/content/perceptual hash) and the rendition-key/fingerprint
 * passes in MediaSelectionService all argue from URLs, filenames or a 64-bit
 * dHash — none of which can tell that two different wire photos show the
 * same generic scene (two similar shots of the same airline's aircraft, two
 * near-identical stock photos of transmission towers), or that a crop/
 * rotation/watermark hides what dHash would otherwise catch. Only a model
 * looking at the actual pixels can, which is what let editorially redundant
 * images reach the channel side by side in the same album.
 *
 * Unlike the AI relevance/caption seams, there is deliberately no config
 * toggle and no non-AI fallback here: a duplicate reaching the channel is
 * the exact failure this class exists to close, so a provider failure
 * throws rather than degrading to the pixel-only result — the selection job
 * already retries with backoff and releases its scheduler claim on
 * exhaustion (see SelectMediaForPublishingJob), so a delayed post is the
 * worst case, not a duplicate one.
 */
class AiImageCurator
{
    public function __construct(
        private readonly StructuredOutputClientInterface $client,
        private readonly MediaStorageService $storage,
        private readonly BoundedHttpFetcher $fetcher,
    ) {}

    /**
     * @param  Collection<int, MediaAsset>  $ranked  best-first
     * @return Collection<int, MediaAsset> best-first, redundant entries collapsed
     */
    public function curate(Collection $ranked, NewsItem $newsItem): Collection
    {
        if ($ranked->count() <= 1) {
            return $ranked;
        }

        $maxCandidates = (int) config('media.curation.max_candidates', 8);
        $candidates = $ranked->take($maxCandidates)->values();
        $rest = $ranked->slice($maxCandidates)->values();

        $images = [];
        $loaded = [];

        foreach ($candidates as $asset) {
            if (! $asset->type->isVisual()) {
                continue;
            }

            $bytes = $this->readBytes($asset);

            if ($bytes === null) {
                continue;
            }

            $images[] = $bytes;
            $loaded[] = $asset;
        }

        $loadedIds = array_map(fn (MediaAsset $a) => $a->id, $loaded);
        $unjudged = $candidates->reject(fn (MediaAsset $a) => in_array($a->id, $loadedIds, true))->values();

        // Nothing to compare visually (a lone loadable image, a video-only
        // pool, or every fetch failing) — nothing this class can improve on.
        if (count($images) <= 1) {
            return $candidates->concat($rest)->values();
        }

        $response = $this->client->generate(
            operation: 'media-curation',
            prompt: $this->prompt($newsItem, count($images)),
            schema: $this->schema(),
            maxOutputTokens: (int) config('media.curation.max_output_tokens'),
            temperature: 0.0,
            timeoutSeconds: (int) config('media.curation.timeout_seconds'),
            images: $images,
        );

        $groups = $this->parseGroups($response['redundant_groups'] ?? null, count($loaded));
        $rankedRelevant = $this->parseIndices($response['ranked_relevant'] ?? null, count($loaded));

        if ($rankedRelevant === null) {
            throw new \RuntimeException('AI curation response omitted a usable ranked_relevant list.');
        }

        $survivors = collect();
        $seenGroups = [];

        foreach ($rankedRelevant as $index) {
            $groupId = $groups[$index] ?? null;

            if ($groupId !== null) {
                if (isset($seenGroups[$groupId])) {
                    continue;
                }
                $seenGroups[$groupId] = true;
            }

            $survivors->push($loaded[$index]);
        }

        $this->recordGroupDuplicates($groups, $loaded, $survivors);

        PipelineLogger::info('media.curation_completed', [
            'news_item_id' => $newsItem->id,
            'candidate_count' => count($loaded),
            'redundant_group_count' => count(array_unique($groups)),
            'kept_count' => $survivors->count(),
        ]);

        return $survivors->concat($unjudged)->concat($rest)->values();
    }

    private function prompt(NewsItem $newsItem, int $count): string
    {
        $title = $newsItem->title ?? '';
        $facts = Str::limit(strip_tags((string) $newsItem->content), 600);

        $lastIndex = $count - 1;

        return <<<PROMPT
            {$count} candidate images for one Telegram post, indexed 0-{$lastIndex}
            in the order attached. JSON only.

            Story: {$title}
            Facts: {$facts}

            redundant_groups: group indices that show the same photo, the same
            subject from a similar angle, or would add nothing shown together
            (e.g. two generic photos of the same kind of scene). Groups of 2+
            only; omit an image with no redundant match.

            ranked_relevant: indices that clearly depict THIS story's specific
            subject (not generic/decorative/unrelated), best match first. At
            most one index per redundant_groups group — its sharpest, most
            on-topic member. Empty array if none qualify.
            PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'redundant_groups' => [
                    'type' => 'array',
                    'items' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
                'ranked_relevant' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
            'required' => ['redundant_groups', 'ranked_relevant'],
        ];
    }

    /** @return array{mime_type: string, data: string}|null */
    private function readBytes(MediaAsset $asset): ?array
    {
        $maxBytes = (int) config('media.curation.max_image_bytes');

        if ($asset->file_size !== null && $asset->file_size > $maxBytes) {
            return null;
        }

        if ($asset->storage_path !== null) {
            $bytes = $this->storage->get($asset->storage_path);

            if ($bytes === null || strlen($bytes) > $maxBytes) {
                return null;
            }

            $mimeType = $asset->mime_type ?: 'image/jpeg';

            return Str::startsWith($mimeType, 'image/') ? ['mime_type' => $mimeType, 'data' => base64_encode($bytes)] : null;
        }

        try {
            $bytes = $this->fetcher->downloadToMemory(
                $asset->original_url,
                $maxBytes,
                timeoutSeconds: (int) config('media.curation.timeout_seconds'),
            );
        } catch (Throwable $e) {
            PipelineLogger::exception('media.curation_fetch_failed', $e, [
                'media_asset_id' => $asset->id,
                'asset_url' => PipelineLogger::url($asset->original_url),
            ], 'warning');

            return null;
        }

        $info = @getimagesizefromstring($bytes);
        $mimeType = $info['mime'] ?? $asset->mime_type ?? 'image/jpeg';

        return Str::startsWith($mimeType, 'image/') ? ['mime_type' => $mimeType, 'data' => base64_encode($bytes)] : null;
    }

    /**
     * @return array<int, int> loaded-index => group id, only for indices the
     *                         model put in a group of 2 or more
     */
    private function parseGroups(mixed $raw, int $count): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $map = [];

        foreach (array_values($raw) as $groupId => $group) {
            if (! is_array($group)) {
                continue;
            }

            $indices = array_values(array_unique(array_filter(
                $group,
                fn ($i) => is_int($i) && $i >= 0 && $i < $count,
            )));

            if (count($indices) < 2) {
                continue;
            }

            foreach ($indices as $i) {
                $map[$i] = $groupId;
            }
        }

        return $map;
    }

    /** @return list<int>|null */
    private function parseIndices(mixed $raw, int $count): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $seen = [];
        $result = [];

        foreach ($raw as $i) {
            if (! is_int($i) || $i < 0 || $i >= $count || isset($seen[$i])) {
                continue;
            }

            $seen[$i] = true;
            $result[] = $i;
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $groups
     * @param  list<MediaAsset>  $loaded
     * @param  Collection<int, MediaAsset>  $survivors
     */
    private function recordGroupDuplicates(array $groups, array $loaded, Collection $survivors): void
    {
        if ($groups === []) {
            return;
        }

        $survivorIds = $survivors->pluck('id')->all();
        $byGroup = [];

        foreach ($groups as $index => $groupId) {
            $byGroup[$groupId][] = $loaded[$index];
        }

        foreach ($byGroup as $members) {
            $keeper = collect($members)->first(fn (MediaAsset $a) => in_array($a->id, $survivorIds, true));

            if (! $keeper) {
                continue;
            }

            foreach ($members as $member) {
                if ($member->id === $keeper->id || $member->duplicate_of_id || $member->duplicates()->exists()) {
                    continue;
                }

                PipelineLogger::info('media.selection_duplicate_dropped', [
                    'media_asset_id' => $member->id,
                    'duplicate_of_id' => $keeper->id,
                    'duplicate_url' => PipelineLogger::url($member->original_url),
                    'kept_url' => PipelineLogger::url($keeper->original_url),
                    'matched_on' => 'ai_vision',
                ]);

                $member->update([
                    'status' => MediaStatus::Duplicate,
                    'duplicate_of_id' => $keeper->id,
                    'processed_at' => now(),
                ]);
            }
        }
    }
}
