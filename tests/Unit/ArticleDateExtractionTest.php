<?php

namespace Tests\Unit;

use App\Services\News\ArticleContentExtractor;
use Tests\TestCase;

/**
 * Publish-date extraction, which the entire freshness policy rests on.
 *
 * A wrong date is far worse here than a missing one. An article with no date is
 * simply not fresh and gets dropped; an article stamped with an invented recent
 * date sails through every filter and reaches the channel as today's news. That
 * is not hypothetical — a Stability AI post from 10 September 2025 was
 * published to the channel on 11 September 2026 because `<time datetime="Sep 10">`
 * carries no year and Carbon fills a missing year in with the current one.
 */
class ArticleDateExtractionTest extends TestCase
{
    private function dateOf(string $html): ?string
    {
        return app(ArticleContentExtractor::class)->extract($html)->publishedAt?->toDateString();
    }

    private function page(string $head, string $body = '<p>'.self::FILLER.'</p>'): string
    {
        return "<html><head><title>A story</title>{$head}</head><body><article><h1>A story</h1>{$body}</article></body></html>";
    }

    private const FILLER = 'A sufficiently long paragraph of article body text so the content extractor has something real to return here.';

    public function test_a_yearless_time_element_is_refused_rather_than_assigned_this_year(): void
    {
        // The exact markup that published a year-old article as today's news.
        $this->assertNull($this->dateOf($this->page('<meta name="x" content="y">',
            '<time class="dt-published" datetime="Sep 10"><span>Sep 10</span></time><p>'.self::FILLER.'</p>')));
    }

    public function test_relative_phrasings_are_refused(): void
    {
        // Only meaningful against a render time we do not have. Guessing one
        // invents freshness.
        foreach (['2 days ago', 'yesterday', 'today', 'last Tuesday'] as $value) {
            $this->assertNull($this->dateOf($this->page('',
                '<time datetime="'.$value.'"></time><p>'.self::FILLER.'</p>')), $value);
        }
    }

    public function test_the_real_date_wins_over_a_yearless_time_element(): void
    {
        // Stability AI ships both: `itemprop="datePublished"` with the true
        // date, and a Squarespace `<time>` without a year.
        $html = $this->page('<meta itemprop="datePublished" content="2025-09-10T14:07:07+0000"/>',
            '<time class="dt-published" datetime="Sep 10"><span>Sep 10</span></time><p>'.self::FILLER.'</p>');

        $this->assertSame('2025-09-10', $this->dateOf($html));
    }

    public function test_json_ld_wins_over_a_yearless_time_element(): void
    {
        // JSON-LD used to be consulted *after* <time>, which is how the
        // yearless value won despite the correct date being on the page.
        $html = '<html><head><title>A story</title>'
            .'<script type="application/ld+json">{"@type":"NewsArticle","datePublished":"2025-09-10T14:07:07+0000"}</script>'
            .'</head><body><article><h1>A story</h1>'
            .'<time datetime="Sep 10"></time><p>'.self::FILLER.'</p></article></body></html>';

        $this->assertSame('2025-09-10', $this->dateOf($html));
    }

    public function test_an_itemprop_date_is_read_at_all(): void
    {
        // schema.org microdata was not in the meta scan, which only looked at
        // `property` and `name`.
        $this->assertSame('2026-09-11', $this->dateOf(
            $this->page('<meta itemprop="datePublished" content="2026-09-11T08:00:00+0000"/>')));
    }

    public function test_a_standard_published_time_meta_tag_still_works(): void
    {
        $this->assertSame('2026-09-11', $this->dateOf(
            $this->page('<meta property="article:published_time" content="2026-09-11T06:30:00+00:00"/>')));
    }

    public function test_a_time_element_that_does_state_its_year_is_still_trusted(): void
    {
        $this->assertSame('2026-09-11', $this->dateOf($this->page('',
            '<time datetime="2026-09-11T09:15:00+00:00"></time><p>'.self::FILLER.'</p>')));
    }

    public function test_the_first_usable_time_element_is_taken_when_an_earlier_one_is_yearless(): void
    {
        // Refusing a yearless value must not abandon the element scan entirely.
        $html = $this->page('', '<time datetime="Sep 10"></time>'
            .'<time datetime="2026-09-11T09:15:00+00:00"></time><p>'.self::FILLER.'</p>');

        $this->assertSame('2026-09-11', $this->dateOf($html));
    }

    public function test_a_visible_text_date_with_a_year_is_still_read(): void
    {
        // anthropic.com exposes its date nowhere machine-readable. Dropping
        // this fallback would silently discard every article from that source.
        $html = '<html><head><title>Claude update</title></head><body><article>'
            .'<h1>Claude update</h1>Sep 11, 2026<p>'.self::FILLER.'</p></article></body></html>';

        $this->assertSame('2026-09-11', $this->dateOf($html));
    }

    public function test_a_far_future_date_is_refused(): void
    {
        $this->assertNull($this->dateOf(
            $this->page('<meta property="article:published_time" content="2099-01-01T00:00:00+00:00"/>')));
    }
}
