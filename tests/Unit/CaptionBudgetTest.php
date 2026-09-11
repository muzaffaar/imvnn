<?php

namespace Tests\Unit;

use App\Services\Telegram\CaptionBudget;
use App\Services\Telegram\TelegramHtml;
use PHPUnit\Framework\TestCase;

class CaptionBudgetTest extends TestCase
{
    public function test_truncation_preserves_entities_and_unicode_budget(): void
    {
        $caption = CaptionBudget::assemble('<b>Source</b>', [['title' => 'Headline', 'body' => TelegramHtml::escape(str_repeat('😀<&> ', 500))]], null, '🔗 <a href="https://example.test/story">manba</a>', null, 1024);
        $visible = html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertLessThanOrEqual(1000, strlen(mb_convert_encoding($visible, 'UTF-16LE', 'UTF-8')) / 2);
        $this->assertDoesNotMatchRegularExpression('/&(?!amp;|lt;|gt;)/', $caption);
        $this->assertSame(substr_count($caption, '<b>'), substr_count($caption, '</b>'));
    }

    public function test_oversized_header_cannot_exceed_caption_limit(): void
    {
        $caption = CaptionBudget::assemble('<b>'.str_repeat('Source ', 500).'</b>', [['body' => 'Body']], null, '🔗 <a href="https://example.test/story">manba</a>', null, 1024);
        $this->assertLessThanOrEqual(1000, mb_strlen(html_entity_decode(strip_tags($caption))));
        $this->assertStringContainsString('href="https://example.test/story"', $caption);
    }

    public function test_link_is_at_the_bottom_before_hashtags_and_title_has_an_empty_row_before_summary(): void
    {
        $caption = CaptionBudget::assemble(
            null,
            [['title' => 'Sarlavha', 'body' => 'Qisqa xulosa']],
            null,
            '🔗 <a href="https://example.test/story">manba</a>',
            '#suniyintellekt',
            1024,
        );

        $this->assertSame(
            "<b>Sarlavha</b>\n\nQisqa xulosa\n\n🔗 <a href=\"https://example.test/story\">manba</a>\n\n#suniyintellekt",
            $caption,
        );
    }
}
