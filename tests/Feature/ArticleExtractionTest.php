<?php

namespace Tests\Feature;

use App\DTOs\ExtractionContext;
use App\Services\Media\Extraction\HtmlContentExtractor;
use App\Services\News\ArticleContentExtractor;
use Tests\TestCase;

class ArticleExtractionTest extends TestCase
{
    public function test_lazy_original_is_selected_and_sidebar_media_is_excluded(): void
    {
        $html = '<article><img src="placeholder.jpg" data-src="original.jpg" data-srcset="small.jpg 200w, large.jpg 1600w"><aside><img src="advert.jpg"></aside></article>';
        $media = (new HtmlContentExtractor)->extract(new ExtractionContext('test', 'https://example.com/story', html: $html));
        $this->assertCount(1, $media);
        $this->assertSame('https://example.com/large.jpg', $media->first()->url);
    }

    public function test_sidebar_paragraphs_do_not_enter_article_summary(): void
    {
        $body = 'The researchers published the results of their latest artificial intelligence study.';
        $garbage = 'Subscribe to our newsletter for exciting offers and unrelated daily promotions.';
        $article = (new ArticleContentExtractor)->extract('<article><p>'.$body.'</p><aside><p>'.$garbage.'</p></aside></article>');
        $this->assertSame($body, $article->content);
    }
}
