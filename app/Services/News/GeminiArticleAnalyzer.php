<?php

namespace App\Services\News;

use App\DTOs\ArticleAnalysisResult;
use App\DTOs\ParsedArticle;
use App\DTOs\RawArticleCandidate;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * One Gemini call does both jobs at once: cleans up the article's
 * title/content AND judges AI-relevance, using Gemini's structured-output
 * mode (responseSchema) so the reply is guaranteed-parseable JSON rather
 * than free text we'd have to coax into a shape.
 *
 * Cost is bounded two ways (see config('news_sources.gemini'), no
 * cross-request budget tracking):
 *   - input: the article's plain text is truncated to `max_input_chars`
 *     before being sent, regardless of the source page's actual length.
 *   - output: `generationConfig.maxOutputTokens` caps the response size —
 *     the schema only ever asks for a boolean and two short strings.
 *
 * Never call this directly from NewsIngestionService — go through
 * FallbackArticleAnalyzer, which is what actually handles a Gemini failure
 * (rate limit, quota, network error, malformed response) turning into a
 * clean fallback to the free heuristic path instead of failing the job.
 */
class GeminiArticleAnalyzer implements ArticleAnalyzerInterface
{
    public function __construct(private readonly Client $client) {}

    public function analyze(RawArticleCandidate $candidate, ParsedArticle $heuristicParse, string $rawHtml): ArticleAnalysisResult
    {
        $apiKey = config('services.gemini.api_key');
        if (empty($apiKey)) {
            throw new GeminiAnalysisException('GEMINI_API_KEY is not configured.');
        }

        $title = $heuristicParse->title ?? $candidate->title ?? '';
        $text = $this->plainText($rawHtml, config('news_sources.gemini.max_input_chars'));

        $response = $this->call($apiKey, $title, $text);

        return new ArticleAnalysisResult(
            isAiRelated: (bool) $response['is_ai_related'],
            title: $this->orNull($response['title'] ?? null) ?? $title,
            content: $this->orNull($response['content'] ?? null) ?? $heuristicParse->content,
            analyzedBy: 'gemini',
        );
    }

    /** @return array{is_ai_related: bool, title: ?string, content: ?string} */
    private function call(string $apiKey, string $title, string $text): array
    {
        $model = config('services.gemini.model');

        $body = [
            'contents' => [[
                'parts' => [['text' => $this->prompt($title, $text)]],
            ]],
            'generationConfig' => [
                'maxOutputTokens' => config('news_sources.gemini.max_output_tokens'),
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'is_ai_related' => ['type' => 'boolean'],
                        'title' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                    'required' => ['is_ai_related', 'title', 'content'],
                ],
            ],
        ];

        try {
            $response = $this->client->post("v1beta/models/{$model}:generateContent", [
                'query' => ['key' => $apiKey],
                'json' => $body,
                'timeout' => config('news_sources.gemini.timeout_seconds'),
            ]);
        } catch (GuzzleException $e) {
            throw new GeminiAnalysisException("Gemini request failed: {$e->getMessage()}", previous: $e);
        }

        $payload = json_decode((string) $response->getBody(), true);
        $this->logUsage($payload['usageMetadata'] ?? []);

        $responseText = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (! $responseText) {
            throw new GeminiAnalysisException('Gemini response had no candidate text.');
        }

        $decoded = json_decode($responseText, true);
        if (! is_array($decoded) || ! array_key_exists('is_ai_related', $decoded)) {
            throw new GeminiAnalysisException('Gemini response was not the expected JSON shape.');
        }

        return $decoded;
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

    private function orNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== '' ? $value : null;
    }

    private function logUsage(array $usage): void
    {
        if (empty($usage)) {
            return;
        }

        Log::info('[gemini-analysis] token usage', [
            'prompt_tokens' => $usage['promptTokenCount'] ?? null,
            'output_tokens' => $usage['candidatesTokenCount'] ?? null,
            'total_tokens' => $usage['totalTokenCount'] ?? null,
        ]);
    }
}
