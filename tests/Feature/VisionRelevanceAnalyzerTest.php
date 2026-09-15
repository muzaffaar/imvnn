<?php

namespace Tests\Feature;

use App\Enums\MediaProvider;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\MediaAsset;
use App\Models\NewsItem;
use App\Services\Ai\StructuredOutputClientInterface;
use App\Services\Media\Scoring\AiVisionRelevanceAnalyzer;
use App\Services\Media\Scoring\FallbackVisionRelevanceAnalyzer;
use App\Services\Media\Scoring\NullVisionRelevanceAnalyzer;
use App\Services\Media\Storage\MediaStorageKeyBuilder;
use App\Services\Media\Storage\MediaStorageService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The real vision-model seam behind VisionRelevanceAnalyzerInterface (see
 * media.relevance and RelevanceAnalysisService, which only ever calls this
 * for a small shortlist of already-cheap-scored candidates per accepted
 * article). Best-image selection depends on this actually judging semantic
 * relevance to the story, not just image quality — and on skipping the
 * expensive call outright for anything that can't or shouldn't be sent
 * (non-visual assets, undownloaded/external assets, oversized files).
 */
class VisionRelevanceAnalyzerTest extends TestCase
{
    public function test_scores_a_downloaded_image_using_the_ai_providers_relevance_score(): void
    {
        Storage::fake(config('media.disk'));
        Storage::disk(config('media.disk'))->put('media/original/test.jpg', 'fake-bytes');

        $asset = $this->image(storagePath: 'media/original/test.jpg', fileSize: 10);
        $analyzer = new AiVisionRelevanceAnalyzer($this->fakeClient(['relevance_score' => 85]), $this->storage());

        $this->assertSame(0.85, $analyzer->scoreRelevance($asset, $this->newsItem()));
    }

    public function test_a_non_visual_asset_never_reaches_the_vision_model(): void
    {
        $asset = $this->image(storagePath: 'media/original/test.jpg', fileSize: 10);
        $asset->type = MediaType::Video;
        $asset->relevance_score = 0.42;

        $client = $this->throwingClient();
        $analyzer = new AiVisionRelevanceAnalyzer($client, $this->storage());

        $this->assertSame(0.42, $analyzer->scoreRelevance($asset, $this->newsItem()));
    }

    public function test_an_undownloaded_external_asset_never_reaches_the_vision_model(): void
    {
        $asset = $this->image(storagePath: null, fileSize: null);
        $asset->relevance_score = 0.5;

        $analyzer = new AiVisionRelevanceAnalyzer($this->throwingClient(), $this->storage());

        $this->assertSame(0.5, $analyzer->scoreRelevance($asset, $this->newsItem()));
    }

    public function test_an_oversized_image_never_reaches_the_vision_model(): void
    {
        config(['media.relevance.max_vision_image_bytes' => 1000]);

        $asset = $this->image(storagePath: 'media/original/big.jpg', fileSize: 5_000_000);
        $asset->relevance_score = 0.6;

        $analyzer = new AiVisionRelevanceAnalyzer($this->throwingClient(), $this->storage());

        $this->assertSame(0.6, $analyzer->scoreRelevance($asset, $this->newsItem()));
    }

    public function test_fallback_analyzer_uses_the_cheap_score_when_ai_is_disabled(): void
    {
        config(['media.relevance.ai_enabled' => false]);

        $asset = $this->image(storagePath: 'media/original/test.jpg', fileSize: 10);
        $asset->relevance_score = 0.33;

        $fallback = new FallbackVisionRelevanceAnalyzer(
            new AiVisionRelevanceAnalyzer($this->throwingClient(), $this->storage()),
            new NullVisionRelevanceAnalyzer,
        );

        $this->assertSame(0.33, $fallback->scoreRelevance($asset, $this->newsItem()));
    }

    public function test_fallback_analyzer_degrades_to_the_cheap_score_on_any_provider_failure(): void
    {
        config(['media.relevance.ai_enabled' => true, 'services.ai.api_key' => 'test-key']);

        $asset = $this->image(storagePath: 'media/original/test.jpg', fileSize: 10);
        $asset->relevance_score = 0.29;

        $fallback = new FallbackVisionRelevanceAnalyzer(
            new AiVisionRelevanceAnalyzer($this->throwingClient(), $this->storage()),
            new NullVisionRelevanceAnalyzer,
        );

        $this->assertSame(0.29, $fallback->scoreRelevance($asset, $this->newsItem()));
    }

    private function storage(): MediaStorageService
    {
        return new MediaStorageService(new MediaStorageKeyBuilder);
    }

    private function image(?string $storagePath, ?int $fileSize): MediaAsset
    {
        return MediaAsset::make([
            'type' => MediaType::Image,
            'status' => MediaStatus::Ready,
            'provider' => $storagePath ? MediaProvider::Downloaded : MediaProvider::External,
            'original_url' => 'https://example.test/image.jpg',
            'storage_path' => $storagePath,
            'file_size' => $fileSize,
            'mime_type' => 'image/jpeg',
        ]);
    }

    private function newsItem(): NewsItem
    {
        return NewsItem::make([
            'title' => 'Central bank deploys AI system',
            'content' => 'Fact-dense content.',
            'url' => 'https://example.test/story',
        ]);
    }

    /** @param array<string, mixed> $response */
    private function fakeClient(array $response): StructuredOutputClientInterface
    {
        return new class($response) implements StructuredOutputClientInterface
        {
            public function __construct(private readonly array $response) {}

            public function generate(string $operation, string $prompt, array $schema, int $maxOutputTokens, float $temperature, int $timeoutSeconds, array $images = []): array
            {
                return $this->response;
            }
        };
    }

    private function throwingClient(): StructuredOutputClientInterface
    {
        return new class implements StructuredOutputClientInterface
        {
            public function generate(string $operation, string $prompt, array $schema, int $maxOutputTokens, float $temperature, int $timeoutSeconds, array $images = []): array
            {
                throw new \RuntimeException('the vision model must not have been called for this candidate');
            }
        };
    }
}
