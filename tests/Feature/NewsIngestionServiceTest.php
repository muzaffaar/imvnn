<?php

namespace Tests\Feature;

use App\DTOs\ArticleAnalysisResult;
use App\DTOs\ParsedArticle;
use App\DTOs\RawArticleCandidate;
use App\Jobs\Media\ExtractMediaJob;
use App\Models\NewsItem;
use App\Models\Source;
use App\Services\News\ArticleAnalyzerInterface;
use App\Services\News\NewsIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The cost-control seam the whole pipeline depends on: media
 * extraction/analysis and caption generation are all AI/network work that
 * only ever runs for a NewsItem row, and a row is only ever created when the
 * (cheap prefilter + AI relevance) verdict says to publish. A rejected
 * candidate must never reach ExtractMediaJob — see NewsIngestionService and
 * ProcessNewsCandidateJob, which dispatches it only when ingest() returns a
 * NewsItem at all.
 */
class NewsIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rejected_candidate_creates_no_news_item_and_never_reaches_media_extraction(): void
    {
        config(['news_sources.topic_filter.enabled' => true]);
        Queue::fake();
        $this->app->instance(ArticleAnalyzerInterface::class, $this->fakeAnalyzer(publish: false));

        $newsItem = app(NewsIngestionService::class)->ingest($this->source(), $this->candidate());

        $this->assertNull($newsItem);
        $this->assertSame(0, NewsItem::count());
        Queue::assertNotPushed(ExtractMediaJob::class);
    }

    public function test_an_accepted_candidate_creates_a_news_item_and_queues_media_extraction(): void
    {
        config(['news_sources.topic_filter.enabled' => true]);
        Queue::fake();
        $this->app->instance(ArticleAnalyzerInterface::class, $this->fakeAnalyzer(publish: true));

        $service = app(NewsIngestionService::class);
        $newsItem = $service->ingest($this->source(), $this->candidate());

        $this->assertNotNull($newsItem);
        $this->assertSame(1, NewsItem::count());

        $service->dispatchMediaExtraction($newsItem);

        Queue::assertPushed(ExtractMediaJob::class, fn (ExtractMediaJob $job) => $job->newsItemId === $newsItem->id);
    }

    private function source(): Source
    {
        return Source::create(['name' => 'Test', 'slug' => 'test-'.uniqid(), 'base_url' => 'https://example.test', 'type' => 'rss']);
    }

    private function candidate(): RawArticleCandidate
    {
        return new RawArticleCandidate(
            url: 'https://example.test/article-'.uniqid(),
            title: 'Some headline',
            publishedAt: CarbonImmutable::now(),
            rawHtml: '<html><body><article><h1>Some headline</h1><p>'.str_repeat('Article body text. ', 20).'</p></article></body></html>',
            // Isolates the AI relevance verdict as the only gate under test —
            // the keyword prefilter and freshness policy are covered elsewhere
            // (IngestionGatesTest).
            skipPrefilter: true,
        );
    }

    private function fakeAnalyzer(bool $publish): ArticleAnalyzerInterface
    {
        return new class($publish) implements ArticleAnalyzerInterface
        {
            public function __construct(private readonly bool $publish) {}

            public function analyze(RawArticleCandidate $candidate, ParsedArticle $heuristicParse, string $rawHtml): ArticleAnalysisResult
            {
                return new ArticleAnalysisResult(
                    isAiRelated: true,
                    title: 'Some headline',
                    content: 'Fact-dense content.',
                    analyzedBy: 'fake',
                    score: $this->publish ? 80 : 10,
                    publish: $this->publish,
                );
            }
        };
    }
}
