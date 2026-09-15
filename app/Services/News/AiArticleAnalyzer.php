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
            Classify this article for a Ministry of Economy and Finance AI
            brief. JSON only, no explanations.

            is_ai_related: substantively about AI/ML/robotics, not a passing
            mention.

            score (0-100), relevance to ministry leadership. High:
            government/public-administration AI; GDP/productivity/macro
            impact; tax, customs, budget; banking/central banks/financial
            supervision; labor markets/automation; AI investment/
            infrastructure; AI regulation/strategy/geopolitics; fraud/
            corruption/procurement detection; economic forecasting; gov/
            finance cybersecurity; major model developments with real
            economic stakes; extraordinary breakthroughs; deployments
            realistic for Uzbekistan. A major global breakthrough can score
            high with no explicit gov/economy angle if its economic/labor/
            security/strategic impact is real. Low: routine product
            updates, generic tutorials/research, repetitive stories,
            marketing disguised as news, vague opinion pieces with no real
            news.

            title: clean version. content: fact-dense extraction (your own
            words, not a vague summary) covering what happened, who did it,
            why/how, what's new, and any concrete result — exact names/
            numbers/dates only, never invent a detail the source doesn't
            support, ~400-900 chars, less if the source is thin.

            Title: {$title}
            Text (ignore boilerplate/nav noise): {$text}
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
