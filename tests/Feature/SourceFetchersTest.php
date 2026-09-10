<?php

namespace Tests\Feature;

use App\Models\Source;
use App\Services\Http\BoundedHttpFetcher;
use App\Services\News\HtmlCrawlSourceFetcher;
use App\Services\News\RssSourceFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceFetchersTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_crawl_resolves_relative_article_links_and_follows_bounded_pagination(): void
    {
        $source = new Source([
            'name' => 'Example News',
            'slug' => 'example-news',
            'fetch_type' => 'html_crawl',
            'source_url' => 'https://example.test/news/',
            'fetch_options' => [
                'article_link_xpath' => '//main//a[@href]',
                'include_url_patterns' => ['https://example.test/news/*'],
                'next_page_xpath' => '//a[@rel="next"]',
                'max_pages' => 2,
                'max_links' => 10,
                'skip_prefilter' => true,
            ],
        ]);

        $fetcher = new HtmlCrawlSourceFetcher($this->boundedFetcher([
            new Response(200, [], <<<'HTML'
                <main>
                    <a href="first-story">First story</a>
                    <a href="/products/not-an-article">Product</a>
                </main>
                <a rel="next" href="?page=2">Next</a>
                HTML),
            new Response(200, [], '<main><a href="/news/second-story">Second story</a></main>'),
        ]));

        $candidates = $fetcher->fetch($source);

        $this->assertSame([
            'https://example.test/news/first-story',
            'https://example.test/news/second-story',
        ], $candidates->pluck('url')->all());
        $this->assertTrue($candidates->first()->skipPrefilter);
    }

    public function test_rss_preserves_all_enclosures_and_reuses_http_validators(): void
    {
        $source = Source::create([
            'name' => 'Example Feed',
            'slug' => 'example-feed',
            'fetch_type' => 'rss',
            'source_url' => 'https://example.test/feed.xml',
            'is_active' => true,
        ]);

        $history = [];
        $fetcher = new RssSourceFetcher($this->boundedFetcher([
            new Response(200, [
                'ETag' => '"feed-v1"',
                'Last-Modified' => 'Wed, 10 Sep 2026 12:00:00 GMT',
            ], <<<'XML'
                <rss version="2.0"><channel><item>
                    <title>AI release</title>
                    <link>https://example.test/news/ai-release</link>
                    <description>Artificial intelligence release</description>
                    <enclosure url="https://cdn.example.test/one.jpg" type="image/jpeg" />
                    <enclosure url="https://cdn.example.test/two.mp4" type="video/mp4" />
                </item></channel></rss>
                XML),
        ], $history));

        $candidates = $fetcher->fetch($source);

        $this->assertCount(1, $candidates);
        $this->assertCount(2, $candidates->first()->rssItem['enclosures']);
        $source->refresh();
        $this->assertSame('"feed-v1"', $source->feed_etag);
        $this->assertSame('Wed, 10 Sep 2026 12:00:00 GMT', $source->feed_last_modified);

        $notModifiedHistory = [];
        $notModifiedFetcher = new RssSourceFetcher($this->boundedFetcher([
            new Response(304),
        ], $notModifiedHistory));

        $this->assertCount(0, $notModifiedFetcher->fetch($source));
        $request = $notModifiedHistory[0]['request'];
        $this->assertSame('"feed-v1"', $request->getHeaderLine('If-None-Match'));
        $this->assertSame('Wed, 10 Sep 2026 12:00:00 GMT', $request->getHeaderLine('If-Modified-Since'));
    }

    /** @param list<Response> $responses @param array<int, array<string, mixed>> $history */
    private function boundedFetcher(array $responses, array &$history = []): BoundedHttpFetcher
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new BoundedHttpFetcher(new Client(['handler' => $stack]));
    }
}
