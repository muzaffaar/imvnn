<?php

namespace App\Services\Media\Deduplication;

use Intervention\Image\ImageManager;

/**
 * Difference hash (dHash): resize to 9x8 grayscale, compare each pixel to its
 * right neighbor. Cheap (no FFT, unlike pHash) and robust to resizing, mild
 * recompression, and small crops — exactly the "reused elsewhere" case in
 * docs/MEDIA_ARCHITECTURE.md Level 3 dedup. Not robust to rotation or heavy
 * cropping; that's what Level 4 (semantic embeddings) is for.
 *
 * Implemented in pure PHP (no ext-gmp/ext-bcmath dependency) since 64 bits is
 * comfortably handled as a plain bit string.
 */
class PerceptualHasher
{
    private const HASH_WIDTH = 9;

    private const HASH_HEIGHT = 8;

    /**
     * Fewest occurrences of the rarer bit value a hash may have and still be
     * treated as an identity. 6 of 64 is far below any real photograph's
     * split and far above a flat image's near-zero.
     */
    private const MIN_MINORITY_BITS = 6;

    public function __construct(private readonly ImageManager $manager) {}

    /** @return string 16-character hex string encoding 64 bits */
    public function hashFromBinary(string $binary): string
    {
        $image = $this->manager->read($binary)
            ->resize(self::HASH_WIDTH, self::HASH_HEIGHT)
            ->greyscale();

        $bits = '';
        for ($y = 0; $y < self::HASH_HEIGHT; $y++) {
            for ($x = 0; $x < self::HASH_WIDTH - 1; $x++) {
                $left = $image->pickColor($x, $y)->toArray()[0];
                $right = $image->pickColor($x + 1, $y)->toArray()[0];
                $bits .= $left < $right ? '1' : '0';
            }
        }

        return $this->bitsToHex($bits);
    }

    /**
     * Whether a hash carries enough structure to be trusted as an identity.
     *
     * dHash compares each pixel to its right neighbour, so an image with no
     * horizontal detail — a solid colour, a flat gradient, a plain backdrop —
     * produces a near-uniform bit string. Two such images land within any
     * sane Hamming threshold of each other while being entirely different
     * pictures, which is exactly the false positive that must never reach the
     * selection stage: it would silently drop a real photo from an album.
     *
     * Requiring both bit values to appear at least MIN_MINORITY_BITS times
     * costs nothing on real photographs (which sit near a 50/50 split) and
     * rejects the degenerate cases outright.
     */
    public function isDistinctive(string $hexHash): bool
    {
        $bits = $this->hexToBits($hexHash);
        $length = strlen($bits);

        if ($length !== self::HASH_HEIGHT * (self::HASH_WIDTH - 1)) {
            return false;
        }

        $ones = substr_count($bits, '1');

        return min($ones, $length - $ones) >= self::MIN_MINORITY_BITS;
    }

    public function hammingDistance(string $hexHashA, string $hexHashB): int
    {
        $bitsA = $this->hexToBits($hexHashA);
        $bitsB = $this->hexToBits($hexHashB);

        if (strlen($bitsA) !== strlen($bitsB)) {
            return PHP_INT_MAX;
        }

        $distance = 0;
        for ($i = 0, $len = strlen($bitsA); $i < $len; $i++) {
            if ($bitsA[$i] !== $bitsB[$i]) {
                $distance++;
            }
        }

        return $distance;
    }

    private function bitsToHex(string $bits): string
    {
        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec(str_pad($nibble, 4, '0')));
        }

        return $hex;
    }

    private function hexToBits(string $hex): string
    {
        $bits = '';
        foreach (str_split($hex) as $char) {
            $bits .= str_pad(base_convert($char, 16, 2), 4, '0', STR_PAD_LEFT);
        }

        return $bits;
    }
}
