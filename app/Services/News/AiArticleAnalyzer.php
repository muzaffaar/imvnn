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

        if (! array_key_exists('is_ai_related', $response)) {
            throw new \RuntimeException('AI analysis response omitted is_ai_related.');
        }

        return new ArticleAnalysisResult(
            isAiRelated: (bool) $response['is_ai_related'],
            title: $this->orNull($response['title'] ?? null) ?? $title,
            content: $this->orNull($response['content'] ?? null) ?? $heuristicParse->content,
            analyzedBy: (string) config('services.ai.provider'),
        );
    }

    /** @return array<string, mixed> */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_ai_related' => ['type' => 'boolean'],
                'title' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ],
            'required' => ['is_ai_related', 'title', 'content'],
        ];
    }

    private function prompt(string $title, string $text): string
    {
        return <<<PROMPT
            You are analyzing a news article for an AI-news aggregation pipeline.

            1. Determine whether the article is substantively about artificial
               intelligence (AI research, models, companies, products, tools,
               regulation, or applications) — not just a passing mention.
            2. Produce a clean article title.
            3. Produce a concise, neutral summary of the article's actual content
               (a few sentences), in your own words rather than copied verbatim.

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
