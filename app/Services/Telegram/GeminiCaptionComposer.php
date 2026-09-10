<?php

namespace App\Services\Telegram;

use App\DTOs\PostMediaPlan;
use App\Enums\PostMediaType;
use App\Models\NewsItem;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the actual post text with Gemini: a short, engaging, bilingual
 * (Uzbek + Russian) summary whose tone is whatever genuinely fits the
 * story — factual, warm, funny, dramatic — rather than one fixed register.
 * No links, no "read more" — this is meant to be the whole post, not a
 * teaser pointing elsewhere.
 *
 * Cost bounded the same way as GeminiArticleAnalyzer (see
 * docs/NEWS_FETCHING.md "Token/cost limits"): input truncated, output
 * capped via maxOutputTokens, no cross-request budget tracking.
 */
class GeminiCaptionComposer implements CaptionComposerInterface
{
    public function __construct(private readonly Client $client) {}

    public function compose(NewsItem $newsItem, PostMediaPlan $plan): string
    {
        $apiKey = config('services.gemini.api_key');
        if (empty($apiKey)) {
            throw new GeminiCaptionException('GEMINI_API_KEY is not configured.');
        }

        $title = $newsItem->title ?? '';
        $body = Str::limit(strip_tags((string) $newsItem->content), config('media.telegram_caption.max_input_chars'));
        $hasVideo = in_array($plan->type, [PostMediaType::Video, PostMediaType::VideoThumbnailFallback], true);

        $response = $this->call($apiKey, $title, $body, $hasVideo);

        $uzbek = $this->orNull($response['uzbek'] ?? null);
        $russian = $this->orNull($response['russian'] ?? null);

        if (! $uzbek && ! $russian) {
            throw new GeminiCaptionException('Gemini caption response had no usable text.');
        }

        $parts = array_filter([
            $uzbek ? "🇺🇿 {$uzbek}" : null,
            $russian ? "🇷🇺 {$russian}" : null,
        ]);

        return implode("\n\n", $parts);
    }

    /** @return array{uzbek: ?string, russian: ?string} */
    private function call(string $apiKey, string $title, string $body, bool $hasVideo): array
    {
        $model = config('services.gemini.model');
        $videoNote = $hasVideo ? "\n\nNote: a video is attached to this post — you may naturally mention that, but do not describe it as a link." : '';

        $prompt = <<<PROMPT
            You are writing a post for a Telegram news channel about AI, read by an
            Uzbek- and Russian-speaking audience.

            Article title: {$title}

            Article summary: {$body}{$videoNote}

            Write TWO short posts (2-4 sentences each) that clearly and accurately
            summarize this story for a general audience — one in Uzbek, one in
            Russian. They don't need to be literal translations of each other, but
            both must convey the same key facts.

            Match whatever tone genuinely fits the story — formal, humorous,
            dramatic, warm, or serious — rather than forcing one fixed style. Emoji
            are fine if they fit naturally.

            Do NOT include any links, URLs, or phrases meaning "read more" /
            "batafsil" / "подробнее". Do not mention the source's name.
            PROMPT;

        $requestBody = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'maxOutputTokens' => config('media.telegram_caption.max_output_tokens'),
                'temperature' => 0.7,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'uzbek' => ['type' => 'string'],
                        'russian' => ['type' => 'string'],
                    ],
                    'required' => ['uzbek', 'russian'],
                ],
            ],
        ];

        try {
            $response = $this->client->post("v1beta/models/{$model}:generateContent", [
                'query' => ['key' => $apiKey],
                'json' => $requestBody,
                'timeout' => config('media.telegram_caption.timeout_seconds'),
            ]);
        } catch (GuzzleException $e) {
            throw new GeminiCaptionException("Gemini caption request failed: {$e->getMessage()}", previous: $e);
        }

        $payload = json_decode((string) $response->getBody(), true);
        $this->logUsage($payload['usageMetadata'] ?? []);

        $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (! $text) {
            throw new GeminiCaptionException('Gemini caption response had no candidate text.');
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new GeminiCaptionException('Gemini caption response was not the expected JSON shape.');
        }

        return $decoded;
    }

    private function orNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== '' ? TelegramHtml::escape($value) : null;
    }

    private function logUsage(array $usage): void
    {
        if (empty($usage)) {
            return;
        }

        Log::info('[gemini-caption] token usage', [
            'prompt_tokens' => $usage['promptTokenCount'] ?? null,
            'output_tokens' => $usage['candidatesTokenCount'] ?? null,
            'total_tokens' => $usage['totalTokenCount'] ?? null,
        ]);
    }
}
