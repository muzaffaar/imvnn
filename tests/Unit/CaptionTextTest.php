<?php

namespace Tests\Unit;

use App\Services\Telegram\CaptionText;
use PHPUnit\Framework\TestCase;

class CaptionTextTest extends TestCase
{
    public function test_sanitize_caps_emoji_without_relying_on_extended_pictographic(): void
    {
        $text = CaptionText::sanitize('Новости 🤖 🤖 🤖 🤖');

        $this->assertSame('Новости 🤖 🤖 🤖', $text);
    }
}
