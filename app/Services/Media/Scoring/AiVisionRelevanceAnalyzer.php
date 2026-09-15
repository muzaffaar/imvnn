<?php

namespace App\Services\Media\Scoring;

use App\Models\MediaAsset;
use App\Models\NewsItem;
use App\Services\Ai\StructuredOutputClientInterface;
use App\Services\Media\Storage\MediaStorageService;
use Illuminate\Support\Str;

/**
 * The real multimodal-model call behind VisionRelevanceAnalyzerInterface.
 * Only ever invoked by RelevanceAnalysisService for the small shortlist that
 * survives cheap rule-based scoring (see
 * media.relevance.max_candidates_for_ai_analysis) — a handful of images per
 * accepted article, never the full extracted set and never for a rejected
 * article at all.
 *
 * Judges whether the image actually depicts THIS story's subject, not just
 * whether it looks like a good photo — a sharp, well-lit stock photo of an
 * unrelated data center must score low here even though MediaQualityScorer
 * would rate it highly on its own.
 */
class AiVisionRelevanceAnalyzer implements VisionRelevanceAnalyzerInterface
{
    public function __construct(
        private readonly StructuredOutputClientInterface $client,
        private readonly MediaStorageService $storage,
    ) {}

    public function scoreRelevance(MediaAsset $asset, NewsItem $newsItem): float
    {
        $image = $this->readImage($asset);

        if ($image === null) {
            return $asset->relevance_score ?? 0.5;
        }

        $response = $this->client->generate(
            operation: 'media-relevance',
            prompt: $this->prompt($newsItem),
            schema: $this->responseSchema(),
            maxOutputTokens: (int) config('media.relevance.ai_max_output_tokens'),
            temperature: 0.0,
            timeoutSeconds: (int) config('media.relevance.ai_timeout_seconds'),
            images: [$image],
        );

        if (! array_key_exists('relevance_score', $response)) {
            throw new \RuntimeException('Vision relevance response omitted relevance_score.');
        }

        $score = max(0, min(100, (int) $response['relevance_score']));

        return $score / 100;
    }

    /**
     * Reads the downloaded original from object storage and base64-encodes
     * it for an inline multimodal request. Returns null (cheap-score
     * fallback) for anything not worth — or not safe — sending: non-visual
     * types, assets that were never downloaded, oversized files, or a
     * mime type that isn't actually an image.
     *
     * @return array{mime_type: string, data: string}|null
     */
    private function readImage(MediaAsset $asset): ?array
    {
        if (! $asset->type->isVisual() || $asset->storage_path === null) {
            return null;
        }

        $maxBytes = (int) config('media.relevance.max_vision_image_bytes');
        if ($asset->file_size !== null && $asset->file_size > $maxBytes) {
            return null;
        }

        $bytes = $this->storage->get($asset->storage_path);
        if ($bytes === null || strlen($bytes) > $maxBytes) {
            return null;
        }

        $mimeType = $asset->mime_type ?: 'image/jpeg';
        if (! Str::startsWith($mimeType, 'image/')) {
            return null;
        }

        return ['mime_type' => $mimeType, 'data' => base64_encode($bytes)];
    }

    private function prompt(NewsItem $newsItem): string
    {
        $title = $newsItem->title ?? '';
        $context = Str::limit(strip_tags((string) $newsItem->content), 600);

        return <<<PROMPT
            Score how well the attached image depicts THIS story's specific
            subject (not just a generic AI/tech picture). JSON only.

            Title: {$title}
            Facts: {$context}

            relevance_score (0-100): 90-100 shows the exact subject/event/
            people; 60-89 on-topic official illustration/chart; 30-59
            generic but related; 0-29 unrelated, decorative, logo/icon/
            avatar/ad/nav asset, or generic stock photo.
            PROMPT;
    }

    /** @return array<string, mixed> */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'relevance_score' => ['type' => 'integer'],
            ],
            'required' => ['relevance_score'],
        ];
    }
}
