<?php

namespace Tests\Feature;

use App\Enums\MediaProvider;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\MediaAsset;
use App\Models\NewsItem;
use App\Models\Source;
use App\Models\TelegramChannel;
use App\Services\Media\ImageDimensionProbe;
use App\Services\Media\Selection\MediaSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolution_is_probed_before_duplicate_rendition_is_discarded(): void
    {
        $source = Source::create(['name' => 'Test', 'slug' => 'test', 'base_url' => 'https://example.com', 'type' => 'rss']);
        $news = NewsItem::create(['source_id' => $source->id, 'title' => 'News', 'url' => 'https://example.com/story']);
        $channel = new TelegramChannel(['rules' => ['min_quality_score' => 0]]);
        $assets = [];
        foreach (['distinctive-picture.width-200.jpg', 'distinctive-picture.width-1600.jpg'] as $name) {
            $asset = MediaAsset::create(['type' => MediaType::Image, 'status' => MediaStatus::Ready, 'provider' => MediaProvider::External, 'original_url' => 'https://example.com/'.$name]);
            $news->mediaAssets()->attach($asset->id, ['position' => count($assets)]);
            $assets[] = $asset;
        }
        $this->mock(ImageDimensionProbe::class)->shouldReceive('probe')->twice()->andReturnUsing(function (MediaAsset $asset) use ($assets) {
            $asset->width = $asset->id === $assets[0]->id ? 200 : 1600;
            $asset->height = $asset->id === $assets[0]->id ? 100 : 900;

            return true;
        });
        $plan = app(MediaSelectionService::class)->selectForNewsItem($news, $channel);
        $this->assertCount(1, $plan->assets);
        $this->assertSame($assets[1]->id, $plan->assets[0]->id);
    }
}
