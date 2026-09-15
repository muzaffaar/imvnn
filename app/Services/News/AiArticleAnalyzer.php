<?php

namespace App\Services\News;

use App\DTOs\ArticleAnalysisResult;
use App\DTOs\ParsedArticle;
use App\DTOs\RawArticleCandidate;
use App\Services\Ai\StructuredOutputClientInterface;

/**
 * One structured-output model call cleans up the article title/content and
 * judges AI relevance. The model transport is provider-neutral: Gemini and
 * OpenAI-compatible endpoints implement StructuredOutputClientInterface.
 */
class AiArticleAnalyzer implements ArticleAnalyzerInterface
{
    public function __construct(private readonly StructuredOutputClientInterface $client) {}

    public function analyze(RawArticleCandidate $candidate, ParsedArticle $heuristicParse, string $rawHtml): ArticleAnalysisResult
    {
        $title = $heuristicParse->title ?? $candidate->title ?? '';
        $text = $this->plainText($rawHtml, (int) config('news_sources.ai.max_input_chars'));

        $response = $this->client->generate(
            operation: 'analysis',
            prompt: $this->prompt($title, $text),
            schema: $this->responseSchema(),
            maxOutputTokens: (int) config('news_sources.ai.max_output_tokens'),
            temperature: 0.1,
            timeoutSeconds: (int) config('news_sources.ai.timeout_seconds'),
        );

        if (! array_key_exists('is_ai_related', $response) || ! array_key_exists('score', $response)) {
            throw new \RuntimeException('AI analysis response omitted is_ai_related or score.');
        }

        $isAiRelated = (bool) $response['is_ai_related'];
        $score = max(0, min(100, (int) $response['score']));

        return new ArticleAnalysisResult(
            isAiRelated: $isAiRelated,
            title: $this->orNull($response['title'] ?? null) ?? $title,
            content: $this->orNull($response['content'] ?? null) ?? $heuristicParse->content,
            analyzedBy: (string) config('services.ai.provider'),
            score: $score,
            publish: $isAiRelated && $score >= (int) config('news_sources.ai.min_publish_score'),
        );
    }

    /** @return array<string, mixed> */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_ai_related' => ['type' => 'boolean'],
                'score' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ],
            'required' => ['is_ai_related', 'score', 'title', 'content'],
        ];
    }

    private function prompt(string $title, string $text): string
    {
        return <<<PROMPT
            Classify this article for a Ministry of Economy and Finance AI news
            brief. No explanations — JSON only.

            is_ai_related: substantively about AI/ML/robotics (research, models,
            companies, products, regulation, applications), not a passing mention.

            score (0-100): relevance/importance to ministry leadership. Score high
            for: government/public-administration AI; GDP, productivity,
            macroeconomic impact; public finance, tax, customs, budget, spending;
            banking, central banks, financial supervision; labor markets and
            automation; AI investment and national infrastructure; AI regulation,
            national strategy, geopolitics; fraud/corruption/procurement
            detection; economic/financial forecasting and government data;
            government/finance-relevant cybersecurity; major AI company/model
            developments with real economic stakes; extraordinary AI
            breakthroughs leaders should know; foreign AI deployments realistically
            applicable to Uzbekistan. A globally significant AI breakthrough can
            still score high with no explicit government/economy angle if its
            economic, labor, security or strategic impact is substantial. Score
            low for: minor product/model updates, routine company announcements,
            narrow low-impact applications, generic tutorials/research,
            repetitive stories.

            Also produce a clean title and a brief neutral summary (your own
            words, a few sentences) of the article's actual content.

            Article title (may be imprecise or missing): {$title}

            Article text (may include boilerplate/navigation noise — ignore it):
            {$text}
            PROMPT;
    }

    private function plainText(string $html, int $maxChars): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        return mb_substr($text, 0, $maxChars);
    }

    private function orNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
