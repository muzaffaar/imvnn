<?php

namespace Tests\Unit;

use App\Services\Media\Deduplication\PerceptualHasher;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * The bit-level contract the image pipeline leans on to decide that two
 * candidates are one picture.
 */
class PerceptualHasherTest extends TestCase
{
    private function hasher(): PerceptualHasher
    {
        return new PerceptualHasher(new ImageManager(new Driver));
    }

    public function test_a_photograph_like_split_is_distinctive(): void
    {
        // The hash measured from the live MIT AI-GUIDE photograph.
        $this->assertTrue($this->hasher()->isDistinctive('30525656c4cc0e1e'));
    }

    public function test_a_flat_image_is_never_treated_as_an_identity(): void
    {
        // dHash compares each pixel to its right neighbour, so an image with no
        // horizontal detail produces a near-uniform bit string that sits within
        // the dedup threshold of every other flat image.
        foreach (['0000000000000000', 'ffffffffffffffff', '0000000000000003'] as $hash) {
            $this->assertFalse($this->hasher()->isDistinctive($hash), $hash);
        }
    }

    public function test_the_distinctiveness_floor_sits_between_the_two(): void
    {
        $hasher = $this->hasher();

        // Five set bits is still a near-flat image; six clears the floor.
        $this->assertFalse($hasher->isDistinctive('0000000000001f00'));
        $this->assertTrue($hasher->isDistinctive('0000000000003f00'));
    }

    public function test_a_malformed_hash_is_rejected_rather_than_compared(): void
    {
        $hasher = $this->hasher();

        $this->assertFalse($hasher->isDistinctive(''));
        $this->assertFalse($hasher->isDistinctive('30525656'));
        $this->assertSame(PHP_INT_MAX, $hasher->hammingDistance('30525656c4cc0e1e', '30525656'));
    }

    public function test_identical_pixels_hash_identically_whatever_the_scale(): void
    {
        $hasher = $this->hasher();
        $large = ImageManager::gd()->create(600, 400)->fill('ffffff');
        $small = ImageManager::gd()->create(300, 200)->fill('ffffff');

        // Two sizes of one flat canvas: the hashes match, and the
        // distinctiveness guard is what stops that from meaning anything.
        $first = $hasher->hashFromBinary((string) $large->toJpeg());
        $second = $hasher->hashFromBinary((string) $small->toJpeg());

        $this->assertSame(0, $hasher->hammingDistance($first, $second));
        $this->assertFalse($hasher->isDistinctive($first));
    }
}
