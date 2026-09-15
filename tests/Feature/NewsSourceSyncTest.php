<?php

namespace Tests\Feature;

use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsSourceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_curated_sources_and_their_fetch_options_are_synced_from_configuration(): void
    {
        $this->artisan('news-sources:sync')->assertSuccessful();

        $this->assertSame(count(config('news_sources.sources')), Source::count());
        $deepMind = Source::where('slug', 'google-deepmind-news')->firstOrFail();
        $meta = Source::where('slug', 'meta-ai-blog')->firstOrFail();

        $this->assertSame('rss', $deepMind->fetch_type->value);
        $this->assertTrue((bool) data_get($deepMind->fetch_options, 'skip_prefilter'));
        $this->assertSame('html_crawl', $meta->fetch_type->value);
        $this->assertSame('//a[contains(@href, "/blog/")]', data_get($meta->fetch_options, 'article_link_xpath'));
    }

    public function test_the_independent_journalism_sources_are_synced(): void
    {
        $this->artisan('news-sources:sync')->assertSuccessful();

        foreach (['techcrunch-ai', 'arstechnica-ai', 'theverge-ai', 'ft-ai'] as $slug) {
            $this->assertTrue(Source::where('slug', $slug)->exists(), "Expected source [{$slug}] to be synced.");
        }
    }

    public function test_the_retired_mit_sources_are_no_longer_configured(): void
    {
        $this->artisan('news-sources:sync')->assertSuccessful();

        foreach (['mit-news-robotics', 'mit-news-ai', 'mit-news-all', 'mit-technology-review-ai'] as $slug) {
            $this->assertFalse(Source::where('slug', $slug)->exists(), "Expected source [{$slug}] to be removed.");
        }
    }

    public function test_a_source_removed_from_config_is_deactivated_rather_than_left_running(): void
    {
        // Simulates a previously-synced source (e.g. the retired MIT News
        // feeds) that predates this config change — still active in the DB
        // even though it no longer appears in config/news_sources.php.
        $stale = Source::create([
            'name' => 'MIT News — All',
            'slug' => 'mit-news-all',
            'fetch_type' => 'rss',
            'source_url' => 'https://news.mit.edu/rss/feed',
            'is_active' => true,
        ]);

        $this->artisan('news-sources:sync')->assertSuccessful();

        $this->assertFalse($stale->refresh()->is_active);
    }
}
