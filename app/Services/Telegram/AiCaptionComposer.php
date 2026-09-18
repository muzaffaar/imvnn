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
        $postWord = $count === 1 ? 'ONE post' : $count.' posts';
        $sharedNote = $count === 1
            ? 'Fits a 1024-char Telegram caption (~500-900 chars of body is a good target); excess is auto-trimmed.'
            : 'Posts share one 1024-char caption — keep each concise; excess is auto-trimmed.';
        $languageRequirement = $count === 1 && ($languages[0]['key'] ?? null) === 'uzbek'
            ? <<<'RULE'
                Uzbek, Latin script only (no Russian sentences, no Cyrillic).
                Keep established terms in English, not translated: AI, ML,
                LLM, API, GPU, CPU, AGI, RAG, NLP, AI agent, Machine
                Learning, Deep Learning, Generative AI, fine-tuning, prompt,
                token, dataset, benchmark, transformer, inference, training,
                model, chatbot, fintech, blockchain, cybersecurity.

                Never translate company, product or model names (OpenAI,
                ChatGPT, Claude, Gemini, NVIDIA...) — copy exactly.
                Everything else: natural Uzbek.
                RULE
            : 'Write ONLY in the requested language(s) — no English.';

        $prompt = <<<PROMPT
            Telegram post for Ministry of Economy and Finance staff — a
            non-technical reader who must understand what happened without
            opening the source.

            Title: {$title}
            Facts: {$body}{$videoNote}

            Write {$postWord}, in {$languageList}. {$languageRequirement}
            Headline under 70 chars, specific — not a copy of the title.

            Plain prose (no headings/bullets). Cover each of these only as
            far as facts support — skip silently what the facts don't give,
            never guess: what happened; who did it; when; how much/how many
            (figures, amounts, counts); why/how it happened; the concrete
            result; the cause behind it. Only when the facts genuinely support
            one — otherwise omit it — mention its link to AI, government, or
            the Ministry of Economy.

            Never write a vague, contentless sentence (e.g. "AI rivojlanishi
            xavfsizlik muammolarini keltirib chiqarmoqda") — every sentence
            must add a concrete fact.

            Match the story's tone; up to 2-3 emoji. {$sharedNote}

            Use ONLY facts given above — no invented names/figures/dates;
            write less if facts are thin.

            Pick 2-4 lowercase hashtag words (letters/digits only, no "#").
            Do not add a URL or mention the source — added separately.
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
