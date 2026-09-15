<?php

namespace Tests\Feature;

use App\DTOs\ParsedArticle;
use App\DTOs\RawArticleCandidate;
use App\Services\Ai\StructuredOutputClientInterface;
use App\Services\News\AiArticleAnalyzer;
use App\Services\News\AiRelevanceFilter;
use App\Services\News\HeuristicArticleAnalyzer;
use App\Services\News\TopicPolicy;
use Tests\TestCase;

/**
 * The gate that decides which articles reach a Ministry of Economy and
 * Finance audience: AiArticleAnalyzer turns the model's 0-100 relevance/
 * impact score into a deterministic `publish` decision via
 * `news_sources.ai.min_publish_score`, so the cutoff is tunable without
 * touching the prompt. See NewsIngestionService, which reads `publish`.
 */
class ArticleAnalyzerTest extends TestCase
{
    public function test_high_score_ai_article_is_marked_for_publishing(): void
    {
        config(['news_sources.ai.min_publish_score' => 55]);

        $analyzer = new AiArticleAnalyzer($this->fakeClient([
            'is_ai_related' => true,
            'score' => 82,
            'title' => 'Central bank pilots AI-driven inflation forecasting',
            'content' => 'A concise neutral summary.',
        ]));

        $result = $analyzer->analyze($this->candidate(), $this->parsed(), '<html>body</html>');

        $this->assertTrue($result->isAiRelated);
        $this->assertSame(82, $result->score);
        $this->assertTrue($result->publish);
    }

    public function test_low_score_ai_article_is_rejected_despite_being_ai_related(): void
    {
        config(['news_sources.ai.min_publish_score' => 55]);

        $analyzer = new AiArticleAnalyzer($this->fakeClient([
            'is_ai_related' => true,
            'score' => 20,
            'title' => 'Startup ships minor chatbot UI update',
            'content' => 'A routine product tweak.',
        ]));

        $result = $analyzer->analyze($this->candidate(), $this->parsed(), '<html>body</html>');

        $this->assertTrue($result->isAiRelated);
        $this->assertSame(20, $result->score);
        $this->assertFalse($result->publish);
    }

    public function test_non_ai_article_is_rejected_even_with_a_high_score(): void
    {
        config(['news_sources.ai.min_publish_score' => 55]);

        // A malformed/over-eager score should never override the topical
        // gate — this is not about AI/ML/robotics at all.
        $analyzer = new AiArticleAnalyzer($this->fakeClient([
            'is_ai_related' => false,
            'score' => 95,
            'title' => 'Local bakery wins regional pastry prize',
            'content' => 'Not AI-related.',
        ]));

        $result = $analyzer->analyze($this->candidate(), $this->parsed(), '<html>body</html>');

        $this->assertFalse($result->isAiRelated);
        $this->assertFalse($result->publish);
    }

    public function test_score_exactly_at_the_threshold_publishes(): void
    {
        config(['news_sources.ai.min_publish_score' => 55]);

        $analyzer = new AiArticleAnalyzer($this->fakeClient([
            'is_ai_related' => true,
            'score' => 55,
            'title' => 'Title',
            'content' => 'Content',
        ]));

        $this->assertTrue($analyzer->analyze($this->candidate(), $this->parsed(), '<html></html>')->publish);
    }

    public function test_out_of_range_score_is_clamped_to_0_100(): void
    {
        config(['news_sources.ai.min_publish_score' => 55]);

        $analyzer = new AiArticleAnalyzer($this->fakeClient([
            'is_ai_related' => true,
            'score' => 500,
            'title' => 'Title',
            'content' => 'Content',
        ]));

        $this->assertSame(100, $analyzer->analyze($this->candidate(), $this->parsed(), '<html></html>')->score);
    }

    public function test_response_missing_score_throws(): void
    {
        $analyzer = new AiArticleAnalyzer($this->fakeClient([
            'is_ai_related' => true,
            'title' => 'Title',
            'content' => 'Content',
        ]));

        $this->expectException(\RuntimeException::class);
        $analyzer->analyze($this->candidate(), $this->parsed(), '<html></html>');
    }

    public function test_heuristic_analyzer_publish_tracks_the_keyword_verdict(): void
    {
        config(['news_sources.topic_filter.enabled' => true]);
        $analyzer = new HeuristicArticleAnalyzer(new AiRelevanceFilter(new TopicPolicy));

        $relevant = $analyzer->analyze(
            new RawArticleCandidate(url: 'https://example.test/a', title: 'New AI model beats benchmark', summary: null),
            new ParsedArticle(title: 'New AI model beats benchmark', content: 'about machine learning', publishedAt: null),
            '<html></html>',
        );
        $this->assertTrue($relevant->isAiRelated);
        $this->assertTrue($relevant->publish);

        $irrelevant = $analyzer->analyze(
            new RawArticleCandidate(url: 'https://example.test/b', title: 'Local bakery wins prize', summary: null),
            new ParsedArticle(title: 'Local bakery wins prize', content: 'pastries', publishedAt: null),
            '<html></html>',
        );
        $this->assertFalse($irrelevant->isAiRelated);
        $this->assertFalse($irrelevant->publish);
    }

    private function candidate(): RawArticleCandidate
    {
        return new RawArticleCandidate(url: 'https://example.test/article', title: 'Fallback title', summary: null);
    }

    private function parsed(): ParsedArticle
    {
        return new ParsedArticle(title: 'Fallback title', content: 'Fallback content', publishedAt: null);
    }

    /** @param array<string, mixed> $response */
    private function fakeClient(array $response): StructuredOutputClientInterface
    {
        return new class($response) implements StructuredOutputClientInterface
        {
            public function __construct(private readonly array $response) {}

            public function generate(
                string $operation,
                string $prompt,
                array $schema,
                int $maxOutputTokens,
                float $temperature,
                int $timeoutSeconds,
            ): array {
                return $this->response;
            }
        };
    }
}
