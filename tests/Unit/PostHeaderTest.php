<?php

namespace Tests\Unit;

use App\Models\NewsItem;
use App\Models\Source;
use App\Services\Telegram\PostHeader;
use PHPUnit\Framework\TestCase;

class PostHeaderTest extends TestCase
{
    public function test_article_link_falls_back_to_the_original_url_when_canonical_url_is_invalid(): void
    {
        $item = new NewsItem([
            'id' => 'article-1',
            'canonical_url' => 'example.test/articles/1',
            'url' => 'https://example.test/articles/1?campaign=telegram&safe=yes',
        ]);
        $item->setRelation('source', new Source(['name' => 'Example source']));

        $link = PostHeader::renderArticleLink($item);

        $this->assertNull(PostHeader::render($item));
        $this->assertStringContainsString('href="https://example.test/articles/1?campaign=telegram&amp;safe=yes"', $link);
        $this->assertStringContainsString('>manba</a>', $link);
    }

    public function test_hashtags_are_normalized_to_lowercase(): void
    {
        $this->assertSame('#suniyintellekt #openai', PostHeader::renderHashtags(['SuniyIntellekt', 'OPENAI', 'openai']));
    }
}
