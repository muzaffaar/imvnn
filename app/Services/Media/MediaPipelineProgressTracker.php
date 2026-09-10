<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks "how many follow-up jobs does this news item's extraction still owe
 * us" so AnalyzeMediaForNewsItemJob fires exactly once, after the last one
 * finishes — regardless of whether that job succeeded or exhausted its
 * retries and failed.
 *
 * Deliberately NOT implemented with Bus::batch(): a batch forces every job
 * in it onto one queue (the batch's own queue option, falling back to the
 * connection default) and ignores each job's individual onQueue() call —
 * see Illuminate\Bus\Batch::add(). This pipeline mixes DownloadMediaJob
 * (media-download) and ProcessVideoJob (video-processing, an isolated pool)
 * in the same fan-out, so a batch would have silently collapsed the video
 * queue isolation this pipeline depends on. A plain atomic counter has no
 * such constraint.
 */
class MediaPipelineProgressTracker
{
    private function key(string $newsItemId): string
    {
        return "media_pipeline_pending:{$newsItemId}";
    }

    public function start(string $newsItemId, int $expectedJobs): void
    {
        Cache::put($this->key($newsItemId), $expectedJobs, now()->addHours(2));
    }

    /** @return bool true if this call completed the last outstanding job for this news item */
    public function complete(string $newsItemId): bool
    {
        $key = $this->key($newsItemId);

        if (! Cache::has($key)) {
            return false;
        }

        $remaining = Cache::decrement($key);

        if ($remaining <= 0) {
            Cache::forget($key);

            return true;
        }

        return false;
    }
}
