<?php

namespace Tests\Unit;

use App\Enums\ImageRole;
use App\Services\Media\Scoring\ImageRoleClassifier;
use Tests\TestCase;

/**
 * Every case here is a real URL observed in the pipeline, because the failure
 * this class prevents was a real post: contributor avatars from a Hugging Face
 * article filled album slots with strangers' faces, out-scoring the article's
 * own figures because an avatar is a genuinely high-quality image.
 *
 * The false-positive cases matter at least as much. Over-matching cost more
 * than under-matching did: broad markers removed an article's own figures and
 * left posts with no pictures at all.
 */
class ImageRoleClassifierTest extends TestCase
{
    private function classify(string $url, ?string $alt = null, ?int $w = null, ?int $h = null): ImageRole
    {
        return app(ImageRoleClassifier::class)->classify($url, $alt, $w, $h);
    }

    public function test_dedicated_avatar_hosts_are_avatars(): void
    {
        $this->assertSame(ImageRole::Avatar, $this->classify(
            'https://cdn-avatars.huggingface.co/v1/production/uploads/no-auth/nMyHN5im81E3Qnf5iZQIt.png', null, 200, 200));
        $this->assertSame(ImageRole::Avatar, $this->classify(
            'https://avatars.githubusercontent.com/u/12345?v=4'));
        $this->assertSame(ImageRole::Avatar, $this->classify(
            'https://secure.gravatar.com/avatar/abc123?s=96'));
    }

    public function test_alt_text_naming_a_person_is_an_avatar(): void
    {
        // Alt text is written for screen readers, so it names the subject plainly.
        $this->assertSame(ImageRole::Avatar, $this->classify(
            'https://cdn.example.com/u/9f8e7d.png', "Multiverse Computing's avatar"));
        $this->assertSame(ImageRole::Avatar, $this->classify(
            'https://cdn.example.com/u/1a2b3c.png', 'Photo of Jane Doe'));
    }

    public function test_a_small_square_is_an_avatar_even_with_no_other_signal(): void
    {
        // The backstop for avatar CDNs nobody has written a pattern for.
        $this->assertSame(ImageRole::Avatar, $this->classify('https://cdn.example.com/x/7f3a91c4.jpg', null, 200, 200));
    }

    public function test_dimensions_are_read_from_the_filename_when_absent(): void
    {
        // A WordPress author headshot, with no width/height attribute on the
        // <img> and nothing avatar-ish in the path.
        $this->assertSame(ImageRole::Avatar, $this->classify(
            'https://blogs.nvidia.com/wp-content/uploads/2021/11/ali-kani-scaled-96x96.jpg'));
    }

    public function test_the_last_size_in_a_chained_rendition_name_wins(): void
    {
        // Resized 1280x680 -> 960x540: the final size is what was served, and
        // reading the first would make this a 1280-wide image that is not square.
        $this->assertSame(ImageRole::Content, $this->classify(
            'https://blogs.nvidia.com/wp-content/uploads/2026/09/nv-blog-1280x680-1-960x540.jpg'));
    }

    public function test_logos_and_icons_are_chrome(): void
    {
        $this->assertSame(ImageRole::Chrome, $this->classify('https://example.com/assets/site-logo.png', null, 300, 80));
        $this->assertSame(ImageRole::Chrome, $this->classify('https://example.com/static/icons/search.png'));
        $this->assertSame(ImageRole::Chrome, $this->classify('https://example.com/img/twitter-icon.png'));
        $this->assertSame(ImageRole::Chrome, $this->classify('https://example.com/img/brand.png', 'Acme logo'));
    }

    public function test_tracking_pixels_are_pixels(): void
    {
        $this->assertSame(ImageRole::Pixel, $this->classify('https://www.nvidia.com/content/dam/1x1-00000000.png', null, 1, 1));
    }

    public function test_article_figures_and_charts_stay_publishable(): void
    {
        $this->assertSame(ImageRole::Content, $this->classify(
            'https://blogs.nvidia.com/wp-content/uploads/2026/09/auto-robotaxi-hero-kv-blog-r7-1920x1080-1.jpg', null, 1920, 1080));
        $this->assertSame(ImageRole::Content, $this->classify(
            'https://example.com/figures/benchmark.jpeg', 'Graph depicting WMT26 benchmark performance', 800, 606));
        $this->assertSame(ImageRole::Content, $this->classify(
            'https://lh3.googleusercontent.com/QgfG84W6jCtJ=w2880-h1620-n-nu-rw-lo', null, 2784, 1566));
    }

    public function test_a_large_square_illustration_is_not_mistaken_for_an_avatar(): void
    {
        // Square product shots and 1:1 social crops are real article art; only
        // *small* squares are avatars.
        $this->assertSame(ImageRole::Content, $this->classify(
            'https://example.com/images/product-shot.jpg', 'The new device', 1200, 1200));
    }

    public function test_unknown_dimensions_do_not_condemn_an_image(): void
    {
        // Reference-only assets have no dimensions until probed. Guessing here
        // would discard real pictures, so the small-square rule stays silent.
        $this->assertSame(ImageRole::Content, $this->classify('https://example.com/media/9f8e7d6c5b4a.jpg'));
    }

    public function test_role_publishability_is_limited_to_content(): void
    {
        $this->assertTrue(ImageRole::Content->isPublishable());

        foreach ([ImageRole::Avatar, ImageRole::Chrome, ImageRole::Pixel, ImageRole::Promo] as $role) {
            $this->assertFalse($role->isPublishable(), "{$role->value} must not be publishable");
        }
    }
}
