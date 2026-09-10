<?php

namespace App\Services\Media\Scoring;

use App\Enums\MediaType;
use App\Models\MediaAsset;

/**
 * Pure rules straight from the Telegram Bot API limits (sendPhoto/sendVideo),
 * used both for quality scoring and to gate what MediaSelectionService is
 * even allowed to pick.
 */
class TelegramCompatibilityChecker
{
    /** @return array{compatible: bool, score: float, reasons: list<string>} */
    public function check(MediaAsset $asset): array
    {
        $reasons = [];

        if ($asset->type === MediaType::Image || $asset->type === MediaType::Gif) {
            return $this->checkPhoto($asset, $reasons);
        }

        if ($asset->type === MediaType::Video) {
            return $this->checkVideo($asset, $reasons);
        }

        // Documents, audio, embeds: no hard Telegram media limits relevant here.
        return ['compatible' => true, 'score' => 1.0, 'reasons' => $reasons];
    }

    private function checkPhoto(MediaAsset $asset, array $reasons): array
    {
        $maxBytes = config('media.telegram.max_photo_bytes');
        $maxDimensionSum = config('media.telegram.photo_max_dimension_sum');
        $minRatio = config('media.telegram.photo_min_aspect_ratio');
        $maxRatio = config('media.telegram.photo_max_aspect_ratio');

        $score = 1.0;

        if ($asset->file_size && $asset->file_size > $maxBytes) {
            $reasons[] = 'exceeds max photo bytes';
            $score -= 0.5;
        }

        if ($asset->width && $asset->height) {
            $sum = $asset->width + $asset->height;
            if ($sum > $maxDimensionSum) {
                $reasons[] = 'width+height exceeds Telegram limit';
                $score -= 0.5;
            }

            $ratio = $asset->width / max(1, $asset->height);
            if ($ratio < $minRatio || $ratio > $maxRatio) {
                $reasons[] = 'aspect ratio outside Telegram-accepted range';
                $score -= 0.5;
            }
        }

        $score = max(0.0, $score);

        return ['compatible' => $score > 0, 'score' => $score, 'reasons' => $reasons];
    }

    private function checkVideo(MediaAsset $asset, array $reasons): array
    {
        $maxBytes = config('media.telegram.max_video_bytes');
        $score = 1.0;

        if ($asset->file_size && $asset->file_size > $maxBytes) {
            $reasons[] = 'exceeds max video bytes for direct upload (would need a Telegram-optimized variant)';
            $score = 0.4; // still usable as a link/thumbnail post, just not a native video upload
        }

        return ['compatible' => true, 'score' => $score, 'reasons' => $reasons];
    }
}
