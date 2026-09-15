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

    public function test_extraordinary_global_breakthrough_is_accepted_without_an_explicit_government_angle(): void
    {
        config(['news_sources.ai.min_publish_score' => 55]);

        // No government/economy keyword at all — the prompt explicitly asks
        // the model to still score high when the strategic/economic impact
        // is substantial on its own.
        $analyzer = new AiArticleAnalyzer($this->fakeClient([
            'is_ai_related' => true,
            'score' => 90,
            'title' => 'Researchers unveil model that solves previously unsolved math problem',
            'content' => 'A fact-dense extraction of the breakthrough.',
        ]));

        $result = $analyzer->analyze($this->candidate(), $this->parsed(), '<html>body</html>');

        $this->assertTrue($result->publish);
    }

    public function test_marketing_disguised_as_news_is_rejected(): void
    {
        config(['news_sources.ai.min_publish_score' => 55]);

        $analyzer = new AiArticleAnalyzer($this->fakeClient([
            'is_ai_related' => true,
            'score' => 15,
            'title' => 'Why our new AI assistant will change how you work',
            'content' => 'Promotional copy with no concrete news.',
        ]));

        $result = $analyzer->analyze($this->candidate(), $this->parsed(), '<html>body</html>');

        $this->assertFalse($result->publish);
    }

    public function test_prompt_asks_for_fact_dense_extraction_not_a_vague_summary(): void
    {
        $captured = null;
        $client = new class($captured) implements StructuredOutputClientInterface
        {
            public string $captured = '';

            public function generate(string $operation, string $prompt, array $schema, int $maxOutputTokens, float $temperature, int $timeoutSeconds, array $images = []): array
            {
                $this->captured = $prompt;

                return ['is_ai_related' => true, 'score' => 80, 'title' => 'Title', 'content' => 'Content'];
            }
        };

        (new AiArticleAnalyzer($client))->analyze($this->candidate(), $this->parsed(), '<html>body</html>');

        $this->assertStringContainsString('never invent a', $client->captured);
        $this->assertStringContainsString('what happened', $client->captured);
        $this->assertStringContainsString('marketing disguised as news', $client->captured);
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
                array $images = [],
            ): array {
                return $this->response;
            }
        };
    }
}
