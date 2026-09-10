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
        $this->assertSame('//main//a[contains(@href, "/blog/")]', data_get($meta->fetch_options, 'article_link_xpath'));
    }
}
