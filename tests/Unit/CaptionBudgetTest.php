<?php

namespace Tests\Unit;

use App\Services\Telegram\CaptionBudget;
use App\Services\Telegram\TelegramHtml;
use PHPUnit\Framework\TestCase;

class CaptionBudgetTest extends TestCase
{
    public function test_truncation_preserves_entities_and_unicode_budget(): void
    {
        $caption = CaptionBudget::assemble('<b>Source</b>', [['title' => 'Headline', 'body' => TelegramHtml::escape(str_repeat('😀<&> ', 500))]], null, null, 1024);
        $visible = html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertLessThanOrEqual(1000, strlen(mb_convert_encoding($visible, 'UTF-16LE', 'UTF-8')) / 2);
        $this->assertDoesNotMatchRegularExpression('/&(?!amp;|lt;|gt;)/', $caption);
        $this->assertSame(substr_count($caption, '<b>'), substr_count($caption, '</b>'));
    }

    public function test_oversized_header_cannot_exceed_caption_limit(): void
    {
        $caption = CaptionBudget::assemble('<b>'.str_repeat('Source ', 500).'</b>', [['body' => 'Body']], null, null, 1024);
        $this->assertLessThanOrEqual(1000, mb_strlen(html_entity_decode(strip_tags($caption))));
    }
}
