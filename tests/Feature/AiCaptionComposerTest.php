<?php

namespace Tests\Feature;

use App\DTOs\PostMediaPlan;
use App\Models\NewsItem;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\StructuredOutputClientInterface;
use App\Services\Telegram\AiCaptionComposer;
use Tests\TestCase;

/**
 * AiCaptionComposer is what actually writes the Telegram post text. Two
 * requirements from the channel brief live entirely in its prompt, not in
 * code, so they are pinned here as prompt-content assertions: (1) keep
 * established AI/tech terminology in English rather than translating it into
 * artificial Uzbek, and (2) never produce a vague, contentless post — a
 * reader must finish understanding what actually happened. The composer's
 * response-handling logic (length/script validation, no artificial short
 * cap) is exercised against a fake provider response.
 */
class AiCaptionComposerTest extends TestCase
{
    public function test_prompt_keeps_established_tech_terminology_in_english(): void
    {
        $capturedPrompt = $this->composeAndCapturePrompt();

        foreach (['AI', 'ML', 'LLM', 'API', 'GPU', 'AGI', 'RAG', 'NLP'] as $term) {
            $this->assertStringContainsString($term, $capturedPrompt);
        }
        $this->assertStringContainsString('Never translate company, product', $capturedPrompt);
    }

    public function test_prompt_forbids_vague_contentless_statements(): void
    {
        $capturedPrompt = $this->composeAndCapturePrompt();

        $this->assertStringContainsString('vague, contentless sentence', $capturedPrompt);
        $this->assertStringContainsString('concrete fact', $capturedPrompt);
    }

    public function test_prompt_requires_a_defensible_government_link_not_a_forced_one(): void
    {
        $capturedPrompt = $this->composeAndCapturePrompt();

        $this->assertStringContainsString('genuinely support', $capturedPrompt);
        $this->assertStringContainsString('otherwise omit it', $capturedPrompt);
    }

    public function test_compose_does_not_truncate_a_grounded_post_down_to_a_short_teaser(): void
    {
        // Long enough that the old "2-3 sentences, 320 characters" prompt
        // instruction would have forced a much shorter reply; nothing in the
        // composer itself should cut this down before CaptionBudget's own
        // 4096-character text-only limit.
        $longBody = 'Markaziy bank sun\'iy intellekt asosidagi tizim joriy qildi va bu orqali soliq yig\'ish jarayoni ancha tezlashdi. '
            .'Tizim moliyaviy hisobotlarni avtomatik tahlil qiladi va xatolarni aniqlaydi. '
            .'Natijada byudjet rejalashtirish sifati oshdi va davlat xarajatlari nazorati yaxshilandi.';

        $client = $this->fakeClient([
            'uzbek_title' => 'Markaziy bank AI tizimini joriy qildi',
            'uzbek' => $longBody,
            'hashtags' => ['markaziybank', 'ai'],
        ]);

        $newsItem = NewsItem::make([
            'title' => 'Central bank deploys AI system',
            'content' => 'Source content.',
            'url' => 'https://example.test/story',
            'canonical_url' => 'https://example.test/story',
        ]);

        $caption = (new AiCaptionComposer($client))->compose($newsItem, PostMediaPlan::none());

        $this->assertStringContainsString('tezlashdi', $caption);
        $this->assertStringContainsString('byudjet rejalashtirish', $caption);
    }

    public function test_compose_rejects_a_reply_that_is_too_short(): void
    {
        $client = $this->fakeClient([
            'uzbek_title' => 'Sarlavha',
            'uzbek' => 'Qisqa.',
            'hashtags' => ['ai'],
        ]);

        $newsItem = NewsItem::make([
            'title' => 'Title', 'content' => 'Content',
            'url' => 'https://example.test/story', 'canonical_url' => 'https://example.test/story',
        ]);

        $this->expectException(AiProviderException::class);
        (new AiCaptionComposer($client))->compose($newsItem, PostMediaPlan::none());
    }

    public function test_compose_rejects_a_reply_in_the_wrong_script(): void
    {
        // Cyrillic, not Latin — the channel requires Uzbek Latin script, so
        // this must be rejected even though it is a plausible Uzbek/Russian
        // sentence, long enough to otherwise pass the length check.
        $client = $this->fakeClient([
            'uzbek_title' => 'Title',
            'uzbek' => str_repeat('Бу хабар лотин алифбосида эмас, шунинг учун рад этилиши керак. ', 2),
            'hashtags' => ['ai'],
        ]);

        $newsItem = NewsItem::make([
            'title' => 'Title', 'content' => 'Content',
            'url' => 'https://example.test/story', 'canonical_url' => 'https://example.test/story',
        ]);

        $this->expectException(AiProviderException::class);
        (new AiCaptionComposer($client))->compose($newsItem, PostMediaPlan::none());
    }

    private function composeAndCapturePrompt(): string
    {
        $client = new class implements StructuredOutputClientInterface
        {
            public string $captured = '';

            public function generate(string $operation, string $prompt, array $schema, int $maxOutputTokens, float $temperature, int $timeoutSeconds, array $images = []): array
            {
                $this->captured = $prompt;

                return [
                    'uzbek_title' => 'Sarlavha',
                    'uzbek' => 'Yetarlicha uzun va aniq faktlarga asoslangan post matni shu yerda bo\'lishi kerak edi.',
                    'hashtags' => ['ai'],
                ];
            }
        };

        $newsItem = NewsItem::make([
            'title' => 'Title', 'content' => 'Content',
            'url' => 'https://example.test/story', 'canonical_url' => 'https://example.test/story',
        ]);

        (new AiCaptionComposer($client))->compose($newsItem, PostMediaPlan::none());

        return $client->captured;
    }

    /** @param array<string, mixed> $response */
    private function fakeClient(array $response): StructuredOutputClientInterface
    {
        return new class($response) implements StructuredOutputClientInterface
        {
            public function __construct(private readonly array $response) {}

            public function generate(string $operation, string $prompt, array $schema, int $maxOutputTokens, float $temperature, int $timeoutSeconds, array $images = []): array
            {
                return $this->response;
            }
        };
    }
}
