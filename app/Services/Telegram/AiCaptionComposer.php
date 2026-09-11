<?php

namespace App\Services\Telegram;

use App\DTOs\PostMediaPlan;
use App\Enums\PostMediaType;
use App\Models\NewsItem;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\StructuredOutputClientInterface;
use Illuminate\Support\Str;

/**
 * Writes the actual post text with the configured AI provider: a short,
 * engaging Uzbek-language summary whose tone genuinely fits the story.
 *
 * Input is truncated and output is capped so the provider cost and latency
 * remain bounded. The transport is intentionally delegated to the provider
 * client, so this class is independent of Gemini, OpenAI, or a local server.
 */
class AiCaptionComposer implements CaptionComposerInterface
{
    public function __construct(private readonly StructuredOutputClientInterface $client) {}

    public function compose(NewsItem $newsItem, PostMediaPlan $plan): string
    {
        $title = $newsItem->title ?? '';
        $body = Str::limit(strip_tags((string) $newsItem->content), config('media.telegram_caption.max_input_chars'));
        $hasVideo = $plan->type === PostMediaType::Video;
        $languages = self::languages();
        $response = $this->call($title, $body, $hasVideo, $languages);

        $sections = [];

        foreach ($languages as $language) {
            $text = $this->orNull($response[$language['key']] ?? null);

            if ($text === null) {
                throw new AiProviderException("AI provider omitted the required {$language['key']} body.");
            }

            // Reject a wrong-language answer and let the job retry; publishing
            // English or Cyrillic text would violate the channel requirement.
            if (! CaptionText::isLongEnough($text)) {
                throw new AiProviderException("AI provider returned too short a body for {$language['key']}.");
            }

            if (! CaptionText::matchesScript($text, $language['script'] ?? null)) {
                throw new AiProviderException("AI provider did not answer in {$language['name']}.");
            }

            $sections[] = [
                'flag' => $language['flag'] ?? null,
                'title' => $this->orNull($response[$language['key'].'_title'] ?? null),
                'body' => $text,
            ];
        }

        if ($sections === []) {
            throw new AiProviderException('AI caption response had no usable text.');
        }

        return CaptionBudget::assemble(
            PostHeader::render($newsItem),
            $sections,
            null,
            PostHeader::renderArticleLink($newsItem),
            PostHeader::renderHashtags($response['hashtags'] ?? []),
            CaptionBudget::limitFor($plan),
        );
    }

    /** @return list<array{key: string, name: string, flag: ?string}> */
    public static function languages(): array
    {
        return config('media.telegram_caption.languages', [
            ['key' => 'uzbek', 'name' => 'Uzbek', 'flag' => null, 'script' => 'Latin'],
        ]);
    }

    /** @param list<array{key: string, name: string, flag: ?string}> $languages */
    private function call(string $title, string $body, bool $hasVideo, array $languages): array
    {
        $videoNote = $hasVideo ? "\n\nNote: a video is attached to this post — you may naturally mention that, but do not describe it as a link." : '';
        $names = array_column($languages, 'name');
        $count = count($names);
        $languageList = $count === 1 ? $names[0] : implode(' and ', [implode(', ', array_slice($names, 0, -1)), end($names)]);
        $postWord = $count === 1 ? 'ONE short post' : $count.' short posts';
        $sharedNote = $count === 1
            ? 'Staying within this length matters: it must fit a 1024-character Telegram caption or it gets trimmed.'
            : 'Staying within these lengths matters: the posts share a single 1024-character Telegram caption and anything over it gets trimmed.';
        $languageRequirement = $count === 1 && ($languages[0]['key'] ?? null) === 'uzbek'
            ? <<<'RULE'
                Uzbek is mandatory. Write every generated field — the headline,
                post body and hashtags — in standard Uzbek using the
                Latin alphabet. Do not write English or Russian, and do not use
                Cyrillic. Translate ordinary terms into Uzbek. Keep an original
                spelling only for proper names, brands, product names, acronyms,
                numbers, or dates where translating would reduce accuracy.
                RULE
            : 'Write ONLY in the requested language(s) — no English.';

        $prompt = <<<PROMPT
            You are writing a post for a Telegram news channel about AI, read by a
            {$languageList}-speaking audience.

            Article title: {$title}

            Article summary: {$body}{$videoNote}

            Write {$postWord} (2-3 sentences, at most 320 characters each) that
            clearly and accurately summarize this story for a general audience,
            in: {$languageList}. Use short, ordinary words. Explain any
            unavoidable technical term in simple language. {$languageRequirement}
            Give each one a short, clear headline (under 70 characters) in its
            own language. {$sharedNote}

            Match whatever tone genuinely fits the story — formal, dramatic,
            warm, or serious — rather than forcing one fixed style. At most two
            or three emoji in total, and only where they genuinely fit.

            Use ONLY facts stated in the article above. Do not add background,
            figures, dates or names that aren't there, and do not overstate
            what happened — the headline must be supported by the summary. If
            the article is thin, write less rather than filling the gap.
            Copy names, numbers and dates exactly as they appear.

            Also pick 2-4 topical hashtag words (no "#", no spaces, letters and
            digits only, all lowercase) — e.g. the model, company, or field the
            story is about.

            Do not add any URL yourself. The application always adds the direct
            source link separately. Do not mention the source's name — it is
            added separately.
            PROMPT;

        return $this->client->generate(
            operation: 'caption',
            prompt: $prompt,
            schema: $this->responseSchema($languages),
            maxOutputTokens: (int) config('media.telegram_caption.max_output_tokens'),
            temperature: 0.7,
            timeoutSeconds: (int) config('media.telegram_caption.timeout_seconds'),
        );
    }

    /** @param list<array{key: string, name: string, flag: ?string}> $languages */
    private function responseSchema(array $languages): array
    {
        $properties = [];

        foreach ($languages as $language) {
            $properties[$language['key'].'_title'] = ['type' => 'string'];
            $properties[$language['key']] = ['type' => 'string'];
        }

        $properties['hashtags'] = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
        ];
    }

    private function orNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = CaptionText::sanitize($value);

        return $value !== '' ? TelegramHtml::escape($value) : null;
    }
}
