<?php

namespace Tests\Feature;

use App\Enums\MediaProvider;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\MediaAsset;
use App\Models\NewsItem;
use App\Services\Ai\StructuredOutputClientInterface;
use App\Services\Http\BoundedHttpFetcher;
use App\Services\Media\Selection\AiImageCurator;
use App\Services\Media\Storage\MediaStorageKeyBuilder;
use App\Services\Media\Storage\MediaStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The mandatory final arbiter of a post's album (see MediaSelectionService,
 * which no longer collapses redundant finalists itself). Unlike
 * VisionRelevanceAnalyzer/AiCaptionComposer there is no fallback binding to
 * test here — a provider failure is expected to propagate so the selection
 * job retries rather than silently publishing a duplicate.
 */
class AiImageCuratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_single_candidate_never_reaches_the_model(): void
    {
        $asset = $this->downloadedImage('a.jpg');
        $curator = new AiImageCurator($this->throwingClient(), $this->storage(), $this->throwingFetcher());

        $result = $curator->curate(collect([$asset]), $this->newsItem());

        $this->assertSame([$asset->id], $result->pluck('id')->all());
    }

    public function test_redundant_images_collapse_to_the_models_pick(): void
    {
        $a = $this->downloadedImage('a.jpg');
        $b = $this->downloadedImage('b.jpg');
        $c = $this->downloadedImage('c.jpg');

        $curator = new AiImageCurator(
            $this->fakeClient(['redundant_groups' => [[0, 1]], 'ranked_relevant' => [1, 2]]),
            $this->storage(),
            $this->throwingFetcher(),
        );

        $result = $curator->curate(collect([$a, $b, $c]), $this->newsItem());

        // Index 0 (a) was dropped for being redundant with index 1 (b); the
        // model's own order for what survives is preserved.
        $this->assertSame([$b->id, $c->id], $result->pluck('id')->all());
    }

    public function test_the_redundant_loser_is_persisted_as_a_resolved_duplicate(): void
    {
        $a = $this->downloadedImage('a.jpg');
        $b = $this->downloadedImage('b.jpg');

        $curator = new AiImageCurator(
            $this->fakeClient(['redundant_groups' => [[0, 1]], 'ranked_relevant' => [1]]),
            $this->storage(),
            $this->throwingFetcher(),
        );

        $curator->curate(collect([$a, $b]), $this->newsItem());

        $this->assertSame($b->id, $a->fresh()->duplicate_of_id);
        $this->assertFalse($a->fresh()->isUsable());
        $this->assertNull($b->fresh()->duplicate_of_id);
    }

    public function test_an_empty_ranked_relevant_list_is_a_legitimate_none_of_these_verdict(): void
    {
        $a = $this->downloadedImage('a.jpg');
        $b = $this->downloadedImage('b.jpg');

        $curator = new AiImageCurator(
            $this->fakeClient(['redundant_groups' => [], 'ranked_relevant' => []]),
            $this->storage(),
            $this->throwingFetcher(),
        );

        $result = $curator->curate(collect([$a, $b]), $this->newsItem());

        $this->assertTrue($result->isEmpty());
    }

    public function test_a_video_among_the_candidates_passes_through_unjudged(): void
    {
        $image1 = $this->downloadedImage('a.jpg');
        $video = MediaAsset::create([
            'type' => MediaType::Video,
            'status' => MediaStatus::Ready,
            'provider' => MediaProvider::Downloaded,
            'original_url' => 'https://example.test/clip.mp4',
        ]);
        $image2 = $this->downloadedImage('b.jpg');

        $curator = new AiImageCurator(
            $this->fakeClient(['redundant_groups' => [], 'ranked_relevant' => [1, 0]]),
            $this->storage(),
            $this->throwingFetcher(),
        );

        $result = $curator->curate(collect([$image1, $video, $image2]), $this->newsItem());

        $this->assertContains($video->id, $result->pluck('id')->all());
        $this->assertSame([$image2->id, $image1->id], $result->reject(fn (MediaAsset $a) => $a->id === $video->id)->pluck('id')->all());
    }

    public function test_a_malformed_response_throws_rather_than_silently_keeping_everything(): void
    {
        $a = $this->downloadedImage('a.jpg');
        $b = $this->downloadedImage('b.jpg');

        $curator = new AiImageCurator(
            $this->fakeClient(['redundant_groups' => []]), // ranked_relevant omitted
            $this->storage(),
            $this->throwingFetcher(),
        );

        $this->expectException(\RuntimeException::class);

        $curator->curate(collect([$a, $b]), $this->newsItem());
    }

    public function test_a_provider_failure_propagates_instead_of_degrading(): void
    {
        $a = $this->downloadedImage('a.jpg');
        $b = $this->downloadedImage('b.jpg');

        $curator = new AiImageCurator($this->throwingClient(), $this->storage(), $this->throwingFetcher());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider unavailable');

        $curator->curate(collect([$a, $b]), $this->newsItem());
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('media.disk'));
    }

    private function downloadedImage(string $name): MediaAsset
    {
        Storage::disk(config('media.disk'))->put("media/original/{$name}", 'fake-bytes');

        return MediaAsset::create([
            'type' => MediaType::Image,
            'status' => MediaStatus::Ready,
            'provider' => MediaProvider::Downloaded,
            'original_url' => "https://example.test/{$name}",
            'storage_path' => "media/original/{$name}",
            'file_size' => 10,
            'mime_type' => 'image/jpeg',
        ]);
    }

    private function storage(): MediaStorageService
    {
        return new MediaStorageService(new MediaStorageKeyBuilder);
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
                throw new \RuntimeException('AI provider unavailable');
            }
        };
    }

    private function throwingFetcher(): BoundedHttpFetcher
    {
        return $this->createMock(BoundedHttpFetcher::class);
    }
}
