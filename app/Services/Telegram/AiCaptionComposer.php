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
            ? 'It has to fit a 1024-character Telegram caption — aim for roughly 500-900 characters of body text, enough to be concrete rather than a one-line teaser, and anything over the limit is trimmed automatically.'
            : 'The posts share a single 1024-character Telegram caption, so keep each one concise enough for that shared budget — anything over it is trimmed automatically.';
        $languageRequirement = $count === 1 && ($languages[0]['key'] ?? null) === 'uzbek'
            ? <<<'RULE'
                Uzbek is mandatory: write the headline and body in natural,
                professional Uzbek using the Latin alphabet. Do not write
                English or Russian sentences, and do not use Cyrillic.

                Keep established international AI/tech/business terms in their
                standard English form instead of translating them into
                artificial Uzbek equivalents — for example: AI, ML, LLM, API,
                GPU, CPU, AGI, RAG, NLP, AI agent, Machine Learning, Deep
                Learning, Generative AI, Open Source, fine-tuning, prompt,
                token, dataset, benchmark, transformer, inference, training,
                cloud, model, chatbot, fintech, big data, blockchain,
                cybersecurity, data center. Never translate company, product
                or model names (OpenAI, ChatGPT, Claude, Gemini, NVIDIA, AWS,
                and so on) — copy them exactly. Write everything else —
                ordinary verbs, connectors, explanations — in natural Uzbek;
                do not overuse English beyond these established terms.
                RULE
            : 'Write ONLY in the requested language(s) — no English.';

        $prompt = <<<PROMPT
            You are writing a post for a Telegram news channel read by staff
            and leadership of a Ministry of Economy and Finance. The reader is
            not a technologist and must understand exactly what happened
            without opening the source article.

            Article title: {$title}

            Article facts: {$body}{$videoNote}

            Write {$postWord}, in: {$languageList}. {$languageRequirement}
            Give each one a short, clear, specific headline (under 70
            characters) in its own language — not a repeat of the article
            title.

            Write flowing prose (no headings, no labeled sections, no bullet
            list) that covers, in this order, only as far as the facts above
            support it:
            1. What actually happened and who did it (company, researchers,
               government) — concretely, never a vague genre statement.
            2. Why it was built/found, or what problem it addresses, and how
               it works, in plain terms.
            3. The concrete result or outcome the article states.
            4. Why this is practically significant, including a specific
               government/economy/finance/Uzbekistan connection ONLY when the
               article genuinely supports one — omit it entirely rather than
               force one.

            Never write a vague, contentless sentence such as "AI
            rivojlanishi xavfsizlik muammolarini keltirib chiqarmoqda" or
            "Kompaniya AI asosidagi moliyaviy tizim yaratdi" that leaves the
            reader not knowing what actually happened. Every sentence must
            add a concrete fact — what was built, found, or decided, by whom,
            how, and what changed — not a generic conclusion like "bu
            texnologiya kelajakda foydali bo'lishi mumkin."

            Match whatever tone genuinely fits the story — formal, dramatic,
            warm, or serious — rather than forcing one fixed style. At most
            two or three emoji in total, and only where they genuinely fit.

            {$sharedNote}

            Use ONLY facts stated above. Do not add background, figures,
            dates or names that aren't there, and do not overstate what
            happened — the headline must be supported by the body. If the
            article facts are thin, write less rather than filling the gap
            with vague language. Copy names, numbers and dates exactly as
            they appear.

            Also pick 2-4 topical hashtag words (no "#", no spaces, letters
            and digits only, all lowercase) — e.g. the model, company, or
            field the story is about.

            Do not add any URL yourself. The application always adds the
            direct source link separately. Do not mention the source's name
            — it is added separately.
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
