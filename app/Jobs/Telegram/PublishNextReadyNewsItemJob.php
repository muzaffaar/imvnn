<?php

namespace App\Jobs\Telegram;

use App\Enums\MediaStatus;
use App\Jobs\Media\SelectMediaForPublishingJob;
use App\Models\NewsItem;
use App\Models\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The publishing scheduler: picks the single best ready-and-unpublished
 * article for a channel, dispatches it, then reschedules itself after a
 * random delay — so even with several good candidates backlogged, the
 * channel only ever gets one post per random(min, max) interval rather than
 * a burst. Started once per channel via `php artisan publishing:start
 * {channel}` (see StartPublishingSchedulerCommand); from then on it keeps
 * itself alive forever via the `finally` block below, regardless of whether
 * a candidate was found or the attempt succeeded.
 *
 * `min_publish_interval_minutes` / `max_publish_interval_minutes` are read
 * from the channel's `rules` JSON (default 15 / 120 minutes), so cadence is
 * configurable per channel like every other publishing rule — see
 * TelegramChannel::rule().
 */
class PublishNextReadyNewsItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Laravel's own retry/backoff is deliberately not used here — the
    // `finally` block is the only thing that keeps the chain alive, so a
    // failed attempt must not multiply into extra parallel chains via retry.
    public int $tries = 1;

    public function __construct(public readonly int $telegramChannelId)
    {
        $this->onQueue(config('media.queues.selection'));
    }

    public function handle(): void
    {
        $channel = TelegramChannel::find($this->telegramChannelId);

        try {
            if (! $channel || ! $channel->is_active) {
                return;
            }

            $candidate = $this->pickBestCandidate();

            if ($candidate && $this->claim($candidate)) {
                SelectMediaForPublishingJob::dispatch($candidate->id, $channel->id);
            }
        } finally {
            $this->reschedule($channel);
        }
    }

    private function pickBestCandidate(): ?NewsItem
    {
        return NewsItem::query()
            ->whereNotNull('media_analysis_completed_at')
            ->whereNull('publish_queued_at')
            ->whereNull('telegram_published_at')
            ->withMax(['mediaAssets as best_quality_score' => function ($query) {
                $query->where('status', MediaStatus::Ready->value);
            }], 'quality_score')
            // Text-only-eligible items (no usable media at all) have a NULL
            // aggregate and rank behind anything with scored media, not ahead
            // of it — Postgres sorts NULLs first by default on DESC.
            ->orderByRaw('best_quality_score DESC NULLS LAST')
            ->orderBy('media_analysis_completed_at')
            ->first();
    }

    /** Atomic claim: true only for whichever concurrent scheduler run gets there first. */
    private function claim(NewsItem $candidate): bool
    {
        $claimed = NewsItem::whereKey($candidate->id)
            ->whereNull('publish_queued_at')
            ->update(['publish_queued_at' => now()]);

        return $claimed > 0;
    }

    private function reschedule(?TelegramChannel $channel): void
    {
        if (! $channel) {
            return;
        }

        $minMinutes = (int) $channel->rule('min_publish_interval_minutes', 15);
        $maxMinutes = (int) $channel->rule('max_publish_interval_minutes', 120);
        $delaySeconds = random_int($minMinutes * 60, $maxMinutes * 60);

        self::dispatch($channel->id)->delay(now()->addSeconds($delaySeconds));

        Log::info("[publishing-scheduler] channel={$channel->id} next check in {$delaySeconds}s");
    }
}
