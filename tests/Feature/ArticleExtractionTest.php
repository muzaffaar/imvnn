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

    public function test_json_ld_publish_date_is_used_when_meta_and_time_are_absent(): void
    {
        $article = (new ArticleContentExtractor)->extract(<<<'HTML'
            <script type="application/ld+json">
                {"@context":"https://schema.org","@type":"NewsArticle","datePublished":"2026-09-10T12:00:00Z"}
            </script>
            <article><h1>AI update</h1><p>This sufficiently long paragraph describes an artificial intelligence update in detail.</p></article>
            HTML);

        $this->assertSame('2026-09-10 12:00:00', $article->publishedAt?->utc()->format('Y-m-d H:i:s'));
    }
}
