<?php

namespace Tests\Feature;

use App\DTOs\ArticleAnalysisResult;
use App\DTOs\RawArticleCandidate;
use App\Jobs\News\FetchNewsSourceJob;
use App\Models\Source;
use App\Services\Http\BoundedHttpFetcher;
use App\Services\Http\BoundedHttpFetchException;
use App\Services\Media\Deduplication\UrlNormalizer;
use App\Services\News\AiRelevanceFilter;
use App\Services\News\ArticleAnalyzerInterface;
use App\Services\News\ArticleContentExtractor;
use App\Services\News\FreshnessPolicy;
use App\Services\News\HtmlCrawlSourceFetcher;
use App\Services\News\NewsIngestionService;
use App\Services\News\NewsSourceFetcherManager;
use App\Services\News\RssSourceFetcher;
use App\Services\News\TopicPolicy;
use Carbon\CarbonImmutable;
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
            'fetch_options' => [
                'skip_prefilter' => true,
                'headers' => [
                    'user-agent' => 'example-source-fetcher/2.0',
                    'X-Source-Test' => 'rss-header-override',
                ],
            ],
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
        $this->assertTrue($candidates->first()->skipPrefilter);
        $source->refresh();
        $this->assertSame('"feed-v1"', $source->feed_etag);
        $this->assertSame('Wed, 10 Sep 2026 12:00:00 GMT', $source->feed_last_modified);
        $this->assertSame('example-source-fetcher/2.0', $history[0]['request']->getHeaderLine('User-Agent'));
        $this->assertSame('rss-header-override', $history[0]['request']->getHeaderLine('X-Source-Test'));

        $notModifiedHistory = [];
        $notModifiedFetcher = new RssSourceFetcher($this->boundedFetcher([
            new Response(304),
        ], $notModifiedHistory));

        $this->assertCount(0, $notModifiedFetcher->fetch($source));
        $request = $notModifiedHistory[0]['request'];
        $this->assertSame('"feed-v1"', $request->getHeaderLine('If-None-Match'));
        $this->assertSame('Wed, 10 Sep 2026 12:00:00 GMT', $request->getHeaderLine('If-Modified-Since'));
    }

    public function test_bounded_fetch_exception_retains_the_http_status_for_retry_policy(): void
    {
        config(['media.limits.user_agent' => 'imvnn-news-fetcher/1.0']);
        $history = [];
        $fetcher = $this->boundedFetcher([new Response(400)], $history);

        try {
            $fetcher->downloadToMemory('https://example.test/rejected', 1024);
            $this->fail('Expected a bounded HTTP exception.');
        } catch (BoundedHttpFetchException $exception) {
            $this->assertSame(400, $exception->statusCode);
            $this->assertSame('https://example.test/rejected', $exception->url);
            $this->assertFalse($exception->isRetryable());
            $this->assertSame('imvnn-news-fetcher/1.0', $history[0]['request']->getHeaderLine('User-Agent'));
        }

        $this->assertTrue((new BoundedHttpFetchException('rate limited', statusCode: 429))->isRetryable());
        $this->assertTrue((new BoundedHttpFetchException('upstream unavailable', statusCode: 503))->isRetryable());
        $this->assertFalse((new BoundedHttpFetchException('not found', statusCode: 404))->isRetryable());
    }

    public function test_fetch_job_fails_permanent_http_errors_without_releasing_a_retry(): void
    {
        $source = Source::create([
            'name' => 'Permanently Rejected',
            'slug' => 'permanently-rejected',
            'fetch_type' => 'html_crawl',
            'source_url' => 'https://example.test/rejected',
            'is_active' => true,
        ]);
        $manager = $this->mock(NewsSourceFetcherManager::class);
        $manager->shouldReceive('fetch')
            ->once()
            ->andThrow(new BoundedHttpFetchException('HTTP 400', statusCode: 400, url: 'https://example.test/rejected'));

        $job = (new FetchNewsSourceJob($source->id))->withFakeQueueInteractions();
        $job->handle($manager);

        $job->assertFailedWith(BoundedHttpFetchException::class);
        $job->assertNotReleased();
    }

    public function test_verified_feed_can_preserve_its_summary_when_article_fetch_is_blocked(): void
    {
        $source = Source::create([
            'name' => 'Blocked Article Feed',
            'slug' => 'blocked-article-feed',
            'fetch_type' => 'rss',
            'source_url' => 'https://example.test/feed.xml',
            'is_active' => true,
        ]);
        $analyzer = $this->mock(ArticleAnalyzerInterface::class);
        $analyzer->shouldReceive('analyze')
            ->once()
            ->andReturn(new ArticleAnalysisResult(true, 'OpenAI model update', 'Feed-provided article summary.', 'heuristic'));

        $service = new NewsIngestionService(
            $this->boundedFetcher([new Response(403)]),
            new ArticleContentExtractor,
            $analyzer,
            new AiRelevanceFilter(new TopicPolicy),
            new UrlNormalizer,
            new FreshnessPolicy,
            new TopicPolicy,
        );

        $newsItem = $service->ingest($source, new RawArticleCandidate(
            url: 'https://example.test/news/model-update',
            title: 'OpenAI model update',
            summary: 'A sufficiently detailed summary supplied by a trusted feed.',
            publishedAt: CarbonImmutable::now(),
            rssItem: ['enclosures' => [['url' => 'https://cdn.example.test/cover.jpg', 'type' => 'image/jpeg']]],
            skipPrefilter: true,
            useFeedContentWhenArticleUnavailable: true,
        ));

        $this->assertNotNull($newsItem);
        $this->assertNull($newsItem->raw_html);
        $this->assertSame('https://cdn.example.test/cover.jpg', data_get($newsItem->source_payload, 'rss_item.enclosures.0.url'));
    }

    /** @param list<Response> $responses @param array<int, array<string, mixed>> $history */
    private function boundedFetcher(array $responses, array &$history = []): BoundedHttpFetcher
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new BoundedHttpFetcher(new Client(['handler' => $stack]));
    }
}
