<?php

namespace Tests\Unit;

use App\Models\NewsItem;
use App\Models\Source;
use App\Services\Telegram\PostHeader;
use PHPUnit\Framework\TestCase;

class PostHeaderTest extends TestCase
{
    public function test_header_has_a_safe_source_link_but_no_publication_timestamp(): void
    {
        $item = new NewsItem([
            'id' => 'article-1',
            'url' => 'https://example.test/articles/1?campaign=telegram&safe=yes',
        ]);
        $item->setRelation('source', new Source(['name' => 'Example source']));

        $header = PostHeader::render($item);

        $this->assertStringContainsString('📰 <b>Example source</b>', $header);
        $this->assertStringContainsString('href="https://example.test/articles/1?campaign=telegram&amp;safe=yes"', $header);
        $this->assertStringContainsString('>manba</a>', $header);
        $this->assertStringNotContainsString('🕒', $header);
    }

    public function test_hashtags_are_normalized_to_lowercase(): void
    {
        $this->assertSame('#suniyintellekt #openai', PostHeader::renderHashtags(['SuniyIntellekt', 'OPENAI', 'openai']));
    }
}
