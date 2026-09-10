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

        $languages = self::languages();
        $response = $this->call($apiKey, $title, $body, $hasVideo, $languages);

        $sections = [];

        foreach ($languages as $language) {
            $text = $this->orNull($response[$language['key']] ?? null);

            if ($text === null) {
                continue;
            }

            // Rejecting here rather than posting anyway: the job retries, so
            // a one-off bad generation costs a delay, whereas an English or
            // half-empty post is visible to every reader.
            if (! CaptionText::isLongEnough($text)) {
                throw new GeminiCaptionException("Gemini returned too short a body for {$language['key']}.");
            }

            if (! CaptionText::matchesScript($text, $language['script'] ?? null)) {
                throw new GeminiCaptionException("Gemini did not answer in {$language['name']}.");
            }

            $sections[] = [
                'flag' => $language['flag'] ?? null,
                'title' => $this->orNull($response[$language['key'].'_title'] ?? null),
                'body' => $text,
            ];
        }

        if ($sections === []) {
            throw new GeminiCaptionException('Gemini caption response had no usable text.');
        }

        return CaptionBudget::assemble(
            PostHeader::render($newsItem),
            $sections,
            $this->humorLine($response['funny_line'] ?? null, $languages[0] ?? []),
            PostHeader::renderHashtags($response['hashtags'] ?? []),
            CaptionBudget::limitFor($plan),
        );
    }

    /**
     * The witty one-liner, rendered as its own italic line so it reads as
     * commentary rather than as part of the reported facts. One per post,
     * in the primary language. Returns null — silently — when the model
     * returned nothing usable, answered in the wrong language, or ran long:
     * a missing quip is never worth failing a post over, and the prompt
     * explicitly allows an empty string when nothing genuinely funny fits.
     *
     * @param  array{key?: string, name?: string, flag?: ?string, script?: ?string}  $language
     */
    private function humorLine(mixed $funnyLine, array $language): ?string
    {
        if (! config('media.telegram_caption.humor_line', true) || ! is_string($funnyLine)) {
            return null;
        }

        $line = $this->orNull($funnyLine);

        if ($line === null
            || ! CaptionText::matchesScript($line, $language['script'] ?? null)
            || mb_strlen($line) > 160) {
            return null;
        }

        return "<i>😄 {$line}</i>";
    }

    /** @return list<array{key: string, name: string, flag: ?string}> */
    public static function languages(): array
    {
        return config('media.telegram_caption.languages', [
            ['key' => 'russian', 'name' => 'Russian', 'flag' => null],
        ]);
    }

    /** @param list<array{key: string, name: string, flag: ?string}> $languages */
    private function call(string $apiKey, string $title, string $body, bool $hasVideo, array $languages): array
    {
        $model = config('services.gemini.model');
        $videoNote = $hasVideo ? "\n\nNote: a video is attached to this post — you may naturally mention that, but do not describe it as a link." : '';

        $names = array_column($languages, 'name');
        $count = count($names);
        $languageList = $count === 1 ? $names[0] : implode(' and ', [implode(', ', array_slice($names, 0, -1)), end($names)]);
        $postWord = $count === 1 ? 'ONE short post' : $count.' short posts';
        $sharedNote = $count === 1
            ? 'Staying within this length matters: it must fit a 1024-character Telegram caption or it gets trimmed.'
            : 'Staying within these lengths matters: the posts share a single 1024-character Telegram caption and anything over it gets trimmed.';

        $prompt = <<<PROMPT
            You are writing a post for a Telegram news channel about AI, read by a
            {$languageList}-speaking audience.

            Article title: {$title}

            Article summary: {$body}{$videoNote}

            Write {$postWord} (2-3 sentences, at most 320 characters each) that
            clearly and accurately summarize this story for a general audience,
            in: {$languageList}. Write ONLY in the requested language(s) — no
            English. Give each one a short, punchy headline (under 70
            characters) in its own language. {$sharedNote}

            Match whatever tone genuinely fits the story — formal, dramatic,
            warm, or serious — rather than forcing one fixed style. At most two
            or three emoji in total, and only where they genuinely fit.

            Use ONLY facts stated in the article above. Do not add background,
            figures, dates or names that aren't there, and do not overstate
            what happened — the headline must be supported by the summary. If
            the article is thin, write less rather than filling the gap.
            Copy names, numbers and dates exactly as they appear.

            Also write `funny_line`: ONE short witty remark (under 140
            characters) reacting to this story, in {$languageList}. Dry,
            observational humour that a reader would smile at — not a pun for
            its own sake, not sarcasm about real people being harmed, and
            never at the expense of the facts. If nothing genuinely funny
            comes to mind for this story, return an empty string rather than
            forcing a joke.

            Also pick 2-4 topical hashtag words (no "#", no spaces, letters and
            digits only) — e.g. the model, company, or field the story is about.

            Do NOT include any links, URLs, or phrases meaning "read more" /
            "batafsil" / "подробнее". Do not mention the source's name — it is
            added separately.
            PROMPT;

        $requestBody = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'maxOutputTokens' => config('media.telegram_caption.max_output_tokens'),
                'temperature' => 0.7,
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->responseSchema($languages),
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

    /**
     * Built from the configured languages so the schema, the prompt and the
     * assembled caption can't fall out of step when a language is added or
     * removed.
     *
     * @param  list<array{key: string, name: string, flag: ?string}>  $languages
     */
    private function responseSchema(array $languages): array
    {
        $properties = [];

        foreach ($languages as $language) {
            $properties[$language['key'].'_title'] = ['type' => 'string'];
            $properties[$language['key']] = ['type' => 'string'];
        }

        $properties['funny_line'] = ['type' => 'string'];
        $properties['hashtags'] = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
        ];
    }

    /**
     * Sanitize before escaping: CaptionText strips invisible/control
     * characters and caps emoji on the raw text, while TelegramHtml::escape
     * makes it safe for parse_mode=HTML. Doing it in the other order would
     * leave the sanitizer inspecting `&lt;` instead of the character itself.
     */
    private function orNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = CaptionText::sanitize($value);

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
