<?php

namespace Tests\Feature;

use App\DTOs\ExtractionContext;
use App\Enums\MediaProvider;
use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\MediaAsset;
use App\Models\NewsItem;
use App\Models\Source;
use App\Models\TelegramChannel;
use App\Services\Media\Deduplication\RenditionKeyBuilder;
use App\Services\Media\Extraction\HtmlContentExtractor;
use App\Services\Media\ImageDimensionProbe;
use App\Services\Media\Selection\MediaSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End of the image pipeline: what actually reaches a Telegram album. Both
 * defects reproduced here were observed in published posts — the same figure
 * occupying two album slots, and contributor avatars crowding out an article's
 * own charts.
 */
class ImageFilteringTest extends TestCase
{
    use RefreshDatabase;

    private function asset(string $url, ?string $alt = null, ?int $w = null, ?int $h = null): MediaAsset
    {
        return MediaAsset::create([
            'type' => MediaType::Image,
            'status' => MediaStatus::Ready,
            'provider' => MediaProvider::External,
            'original_url' => $url,
            'alt_text' => $alt,
            'width' => $w,
            'height' => $h,
        ]);
    }

    /** @param list<MediaAsset> $assets */
    private function newsWith(array $assets): NewsItem
    {
        $source = Source::create(['name' => 'Test', 'slug' => 'test', 'base_url' => 'https://example.com', 'type' => 'rss']);
        $news = NewsItem::create(['source_id' => $source->id, 'title' => 'Robot learns to fold laundry', 'url' => 'https://example.com/story']);

        foreach ($assets as $position => $asset) {
            $news->mediaAssets()->attach($asset->id, ['position' => $position, 'relevance_score' => 0.6]);
        }

        return $news;
    }

    private function channel(): TelegramChannel
    {
        return new TelegramChannel(['rules' => ['min_quality_score' => 0, 'max_images' => 4, 'prefer_video' => false]]);
    }

    private function neverProbe(): void
    {
        // Dimensions are already set on every fixture asset, so a probe would
        // only add network noise.
        $this->mock(ImageDimensionProbe::class)->shouldReceive('probe')->andReturn(false);
    }

    private function extract(string $html): array
    {
        return app(HtmlContentExtractor::class)->extract(new ExtractionContext(
            newsItemId: 'x',
            baseUrl: 'https://example.com/story',
            title: 'Robot',
            html: $html,
        ))->pluck('url')->all();
    }

    public function test_google_cdn_size_suffixes_collapse_to_one_rendition_key(): void
    {
        $keys = app(RenditionKeyBuilder::class);
        $id = 'vOjFcTcdX2GCEB9yktJ7GAfyhTAMozW7scq3TrT9qk1mYw5qE0BdUqI8XQ';

        // One DeepMind figure served at five sizes. Google glues the rendition
        // onto the image id with `=`, no dot and no extension, so none of the
        // dot-anchored rendition patterns touched it and all five looked
        // distinct — two of them reached the same album.
        $first = $keys->keyFor($this->asset("https://lh3.googleusercontent.com/{$id}=w2000-h1260-n-nu"));

        foreach (['=w528-h297-n-nu-rw-lo', '=w1200-h630-n-nu-rw', '=w1920-h1080-n-nu', '=s800'] as $suffix) {
            $this->assertSame($first, $keys->keyFor($this->asset("https://lh3.googleusercontent.com/{$id}{$suffix}")));
        }
    }

    public function test_genuinely_different_google_images_keep_different_keys(): void
    {
        $keys = app(RenditionKeyBuilder::class);

        $this->assertNotSame(
            $keys->keyFor($this->asset('https://lh3.googleusercontent.com/QgfG84W6jCtJCUm9zL1nLLNGTSqdCu=w2880-h1620')),
            $keys->keyFor($this->asset('https://lh3.googleusercontent.com/XtSeskvW7gYRyovhEWB7bkB6ezvdjd=w2880-h1620')),
        );
    }

    public function test_a_crop_marker_collapses_even_when_another_pattern_eats_its_dimensions(): void
    {
        $keys = app(RenditionKeyBuilder::class);
        $base = 'https://storage.googleapis.com/gweb-uniblog-publish-prod/images/';

        // `.max-600x600.format-webp` loses `.format-webp`, then `-600x600`,
        // which strands a bare `.max` where the keyword pattern can no longer
        // see it — so this rendition kept its own key and the same hero image
        // reached one album twice.
        $expected = $keys->keyFor($this->asset($base.'Hero_Image_4.width-1300.png'));

        foreach (['Hero_Image_4.width-2200.format-webp.webp', 'Hero_Image_4.max-600x600.format-webp.webp'] as $file) {
            $this->assertSame($expected, $keys->keyFor($this->asset($base.$file)), $file);
        }
    }

    public function test_a_filename_truncated_per_rendition_still_collapses(): void
    {
        $keys = app(RenditionKeyBuilder::class);
        $base = 'https://storage.googleapis.com/gweb-uniblog-publish-prod/images/';

        // blog.google cuts the stem to a length that varies with the rendition
        // suffix, so one image yields two stems differing by a single character.
        $this->assertTrue($keys->isSamePicture(
            $keys->keyFor($this->asset($base.'LOVE_RENDERED_HERO_BLOG_SANS_LOG.width-2200.format-webp.webp')),
            $keys->keyFor($this->asset($base.'LOVE_RENDERED_HERO_BLOG_SANS_LO.max-600x600.format-webp.webp')),
        ));
        $this->assertTrue($keys->isSamePicture(
            $keys->keyFor($this->asset($base.'Detailed_share_of_Gemini_usage_for_civic_task.width-1300.png')),
            $keys->keyFor($this->asset($base.'Detailed_share_of_Gemini_usage_f.width-2000.format-webp.webp')),
        ));
    }

    public function test_a_numbered_sibling_is_not_mistaken_for_a_truncation(): void
    {
        $keys = app(RenditionKeyBuilder::class);
        $base = 'https://storage.googleapis.com/gweb-uniblog-publish-prod/images/';

        // `love_rendered_inline` is a prefix of `love_rendered_inline_2`, and
        // they are two different pictures from the same article. A truncation
        // cuts mid-word; a numbered sibling stops at a separator.
        $this->assertFalse($keys->isSamePicture(
            $keys->keyFor($this->asset($base.'love_rendered_inline.width-2000.format-webp.webp')),
            $keys->keyFor($this->asset($base.'love_rendered_inline_2.width-2000.format-webp.webp')),
        ));
    }

    public function test_distinct_figures_sharing_a_long_prefix_stay_distinct(): void
    {
        $keys = app(RenditionKeyBuilder::class);
        $base = 'https://blogs.nvidia.com/wp-content/uploads/2026/09/';

        $this->assertFalse($keys->isSamePicture(
            $keys->keyFor($this->asset($base.'robotaxi-momentum-vla-chart.jpeg')),
            $keys->keyFor($this->asset($base.'robotaxi-momentum-synthetic-data-chart.jpeg')),
        ));
    }

    public function test_truncation_matching_never_applies_to_content_hashes(): void
    {
        // A hash is exact. Fuzzy-matching one would merge genuinely different
        // images whose hashes happen to share a prefix.
        $keys = app(RenditionKeyBuilder::class);
        $a = $this->asset('https://example.com/a.jpg');
        $b = $this->asset('https://example.com/b.jpg');
        $a->content_hash = str_repeat('a', 63).'1';
        $b->content_hash = str_repeat('a', 63).'2';

        $this->assertFalse($keys->isSamePicture($keys->keyFor($a), $keys->keyFor($b)));
    }

    public function test_a_truncated_rendition_does_not_take_a_second_album_slot(): void
    {
        $this->neverProbe();
        $base = 'https://storage.googleapis.com/gweb-uniblog-publish-prod/images/';
        $news = $this->newsWith([
            $this->asset($base.'LOVE_RENDERED_HERO_BLOG_SANS_LOG.width-2200.format-webp.webp', null, 2200, 1238),
            $this->asset($base.'LOVE_RENDERED_HERO_BLOG_SANS_LO.max-600x600.format-webp.webp', null, 600, 600),
            $this->asset($base.'love_rendered_inline.width-2000.format-webp.webp', null, 2000, 1125),
            $this->asset($base.'love_rendered_inline_2.width-2000.format-webp.webp', null, 2000, 1125),
        ]);

        $plan = app(MediaSelectionService::class)->selectForNewsItem($news, $this->channel());

        // Three distinct pictures: the hero plus two different inline images.
        $this->assertCount(3, $plan->assets);
    }

    public function test_one_picture_never_takes_two_album_slots(): void
    {
        $this->neverProbe();
        $id = 'vOjFcTcdX2GCEB9yktJ7GAfyhTAMozW7scq3TrT9qk1mYw5qE0BdUqI8XQ';
        $news = $this->newsWith([
            $this->asset("https://lh3.googleusercontent.com/{$id}=w2000-h1260-n-nu", null, 2000, 1260),
            $this->asset("https://lh3.googleusercontent.com/{$id}=w1920-h1080-n-nu", null, 1920, 1080),
            $this->asset('https://lh3.googleusercontent.com/QgfG84W6jCtJCUm9zL=w2880-h1620', null, 2784, 1566),
        ]);

        $plan = app(MediaSelectionService::class)->selectForNewsItem($news, $this->channel());
        $urls = array_map(fn (MediaAsset $a) => $a->original_url, $plan->assets);

        $this->assertCount(2, $plan->assets);
        $this->assertCount(2, array_unique($urls));
    }

    public function test_avatars_are_never_published_even_when_they_outscore_the_figures(): void
    {
        $this->neverProbe();
        $avatars = array_map(
            fn (int $i) => $this->asset("https://cdn-avatars.huggingface.co/v1/production/uploads/user{$i}.png", null, 200, 200),
            range(1, 10),
        );

        // Ten contributor avatars and one real chart, the shape of the Hugging
        // Face article that started this.
        $news = $this->newsWith([
            ...$avatars,
            $this->asset('https://cdn-uploads.huggingface.co/production/uploads/HuauFbdzn.png', 'Narrow-boundary safety diagram', 1448, 1086),
        ]);

        $plan = app(MediaSelectionService::class)->selectForNewsItem($news, $this->channel());

        $this->assertCount(1, $plan->assets);
        $this->assertStringContainsString('cdn-uploads', $plan->assets[0]->original_url);
    }

    public function test_an_article_of_only_avatars_posts_as_text_rather_than_faces(): void
    {
        $this->neverProbe();
        $news = $this->newsWith([
            $this->asset('https://cdn-avatars.huggingface.co/v1/production/uploads/a.png', null, 200, 200),
            $this->asset('https://example.com/assets/site-logo.png', 'Acme logo', 300, 80),
        ]);

        $plan = app(MediaSelectionService::class)->selectForNewsItem($news, $this->channel());

        $this->assertSame([], $plan->assets);
    }

    public function test_byline_and_comment_containers_are_stripped_before_extraction(): void
    {
        $html = '<article>'
            .'<div class="post-author"><img src="https://example.com/u/jane.jpg" alt="Jane"></div>'
            .'<img src="https://example.com/figures/robot-arm.jpg" alt="A robot arm folding a towel">'
            .'<section class="comments"><img src="https://example.com/u/bob.jpg" alt="Bob"></section>'
            .'</article>';

        $this->assertSame(['https://example.com/figures/robot-arm.jpg'], $this->extract($html));
    }

    public function test_a_layout_class_mentioning_a_sidebar_does_not_remove_the_article(): void
    {
        // `sidebar-right` describes the page's layout, not the element's role.
        // Treating it as a container to strip removed an article's own figures
        // and cut one post from seven candidates to four.
        $html = '<div class="page sidebar-right"><article>'
            .'<img src="https://example.com/figures/chart.jpg" alt="Benchmark chart">'
            .'<img src="https://example.com/figures/diagram.jpg" alt="System diagram">'
            .'</article></div>';

        $this->assertCount(2, $this->extract($html));
    }

    public function test_a_class_merely_containing_a_marker_word_is_not_stripped(): void
    {
        // Hyphen padding is what keeps `author` from matching `authoritative`.
        $html = '<article class="authoritative-source">'
            .'<img src="https://example.com/figures/chart.jpg" alt="Benchmark chart">'
            .'</article>';

        $this->assertCount(1, $this->extract($html));
    }

    public function test_a_thumbnail_linking_to_another_article_is_not_this_article_s_picture(): void
    {
        // blog.google wraps every recommended-article thumbnail in
        // `<a class="uni-article-card" href="/other-story">`. Six were extracted
        // for one password-manager article and two reached the published album.
        $html = '<article>'
            .'<img src="https://example.com/figures/hero.jpg" alt="The import flow">'
            .'<a class="uni-article-card" href="/blog/another-story/">'
            .'<img src="https://example.com/cards/other-story.jpg" alt="Another story"></a>'
            .'</article>';

        $this->assertSame(['https://example.com/figures/hero.jpg'], $this->extract($html));
    }

    public function test_a_lightbox_link_to_the_picture_itself_is_kept(): void
    {
        $html = '<article><a href="https://example.com/figures/hero-full.jpg">'
            .'<img src="https://example.com/figures/hero.jpg" alt="The import flow"></a></article>';

        $this->assertSame(['https://example.com/figures/hero.jpg'], $this->extract($html));
    }

    public function test_a_hero_linking_back_to_its_own_article_is_kept(): void
    {
        // Some templates wrap their own hero image in a self-link.
        $html = '<article><a href="/story"><img src="https://example.com/figures/hero.jpg" alt="Hero"></a></article>';

        $this->assertSame(['https://example.com/figures/hero.jpg'], $this->extract($html));
    }

    public function test_a_non_page_link_around_an_image_is_not_treated_as_a_teaser(): void
    {
        $html = '<article><a href="#main"><img src="https://example.com/figures/a.jpg" alt="A"></a>'
            .'<a href="mailto:x@example.com"><img src="https://example.com/figures/b.jpg" alt="B"></a></article>';

        $this->assertCount(2, $this->extract($html));
    }

    public function test_the_related_posts_strip_below_the_article_is_dropped(): void
    {
        // Thumbnails in a "read next" strip are pictures of other articles. They
        // are the hardest kind to recognise individually — real photographs at
        // real sizes with sensible alt text — so position is the signal. One
        // blog.google page contributed six of them.
        $html = '<body><article><p>'.str_repeat('Body prose about the story. ', 12).'</p>'
            .'<img src="https://example.com/figures/figure.jpg" alt="A figure"></article>'
            .'<section><h2>Read next</h2>'
            .'<img src="https://example.com/cards/other-one.jpg" alt="Another story">'
            .'<img src="https://example.com/cards/other-two.jpg" alt="A third story">'
            .'</section></body>';

        $this->assertSame(['https://example.com/figures/figure.jpg'], $this->extract($html));
    }

    public function test_a_hero_above_the_article_body_is_kept(): void
    {
        // Measured across the configured sources, the hero sits *before* the
        // body container as often as inside it — in a page header, a <picture>
        // block, or a sibling <figure>. Trimming above the body would throw
        // away the single most important image on the page, which is why only
        // what follows it is removed.
        $html = '<body><header><img src="https://example.com/figures/hero.jpg" alt="Hero"></header>'
            .'<article><p>'.str_repeat('Body prose about the story. ', 12).'</p>'
            .'<img src="https://example.com/figures/figure.jpg" alt="A figure"></article></body>';

        $urls = $this->extract($html);
        $this->assertContains('https://example.com/figures/hero.jpg', $urls);
        $this->assertContains('https://example.com/figures/figure.jpg', $urls);
    }

    public function test_a_page_with_no_locatable_body_is_left_alone(): void
    {
        // With no anchor there is no "after", so nothing is trimmed rather than
        // everything. Two of the configured sources ship no <article> tag at all.
        $html = '<body><div><img src="https://example.com/a.jpg" alt="A">'
            .'<img src="https://example.com/b.jpg" alt="B"></div></body>';

        $this->assertCount(2, $this->extract($html));
    }

    public function test_the_densest_article_wins_over_a_teaser_card_marked_up_as_an_article(): void
    {
        // Related cards are themselves <article> elements on several sources —
        // 34 of them on one Hugging Face page — so "the first <article>" can
        // land on a card and trim the real story away as if it came after.
        $html = '<body><article class="card"><h3>A teaser</h3></article>'
            .'<article class="post"><p>'.str_repeat('Body prose about the story. ', 12).'</p>'
            .'<img src="https://example.com/figures/figure.jpg" alt="A figure"></article></body>';

        $this->assertSame(['https://example.com/figures/figure.jpg'], $this->extract($html));
    }

    public function test_a_relative_image_path_resolves_against_the_article_url(): void
    {
        // The extraction base URL used to be `canonical_url`, a deduplication
        // key with the scheme stripped. Nothing relative could resolve against
        // it, so relative image paths were silently discarded entirely.
        $html = '<body><article><p>'.str_repeat('Body prose about the story. ', 12).'</p>'
            .'<img src="/media/figure.jpg" alt="A figure"></article></body>';

        $this->assertSame(['https://example.com/media/figure.jpg'], $this->extract($html));
    }

    public function test_a_relatively_linked_teaser_is_recognised(): void
    {
        // Same root cause: with an unresolvable base, the "links to another
        // article" check could never resolve an href, so every card linked with
        // a relative path went through. That is how three other articles'
        // heroes reached a research.google post.
        $html = '<body><article><p>'.str_repeat('Body prose about the story. ', 12).'</p>'
            .'<img src="/media/figure.jpg" alt="A figure">'
            .'<div class="related-posts"><a href="/blog/some-other-story/">'
            .'<img src="/media/other-hero.jpg" alt="Another story"></a></div>'
            .'</article></body>';

        $this->assertSame(['https://example.com/media/figure.jpg'], $this->extract($html));
    }

    public function test_a_lazy_loaded_image_is_taken_over_its_placeholder(): void
    {
        // The real picture lives in a data attribute; `src` holds a blur or a
        // spinner. Reading `src` first publishes the placeholder.
        $html = '<article><img src="data:image/gif;base64,R0lGODlhAQABAAAAACw="'
            .' data-src="https://example.com/figures/real-photo.jpg" alt="A robot"></article>';

        $this->assertSame(['https://example.com/figures/real-photo.jpg'], $this->extract($html));
    }

    public function test_the_largest_srcset_candidate_still_wins(): void
    {
        $html = '<article><img src="https://example.com/s.jpg"'
            .' srcset="https://example.com/small.jpg 400w, https://example.com/large.jpg 1600w" alt="A robot"></article>';

        $this->assertSame(['https://example.com/large.jpg'], $this->extract($html));
    }
}
