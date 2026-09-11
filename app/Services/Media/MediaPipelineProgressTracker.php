<?php

namespace App\Services\Media;

use App\Support\Observability\PipelineLogger;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks "how many follow-up jobs does this news item's extraction still owe
 * us" so AnalyzeMediaForNewsItemJob fires exactly once, after the last one
 * finishes — regardless of whether that job succeeded or exhausted its
 * retries and failed.
 *
 * Biased toward firing rather than withholding: a duplicate analysis pass
 * only repeats idempotent scoring work, while a missed one leaves the article
 * invisible to the publishing scheduler forever.
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

        PipelineLogger::debug('media.progress_started', [
            'news_item_id' => $newsItemId,
            'expected_job_count' => $expectedJobs,
        ]);
    }

    /** @return bool true if this call completed the last outstanding job for this news item */
    public function complete(string $newsItemId): bool
    {
        $key = $this->key($newsItemId);

        if (! Cache::has($key)) {
            // The counter is the only record of how much work is outstanding,
            // and it lives in a cache: a two-hour expiry, a flushed store or a
            // restarted Redis all erase it. Reporting "not finished" then
            // means the analysis job is never dispatched and the article can
            // never be published, with nothing failed to retry. Treat a lost
            // counter as the last job instead — analysis is idempotent and
            // cheap to repeat, whereas a stranded article is invisible.
            PipelineLogger::warning('media.progress_state_missing', [
                'news_item_id' => $newsItemId,
                'treated_as_final' => true,
            ]);

            return true;
        }

        $remaining = Cache::decrement($key);

        if ($remaining <= 0) {
            Cache::forget($key);

            PipelineLogger::info('media.progress_completed', ['news_item_id' => $newsItemId]);

            return true;
        }

        PipelineLogger::debug('media.progress_waiting', [
            'news_item_id' => $newsItemId,
            'remaining_job_count' => $remaining,
        ]);

        return false;
    }
}
