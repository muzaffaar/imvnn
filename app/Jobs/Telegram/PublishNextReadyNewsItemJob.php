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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The publishing scheduler: picks the single best ready-and-unpublished
 * article for a channel, dispatches it, then reschedules itself — so even
 * with several good candidates backlogged, the channel only ever gets one
 * post at a time, never a burst. Started per channel via
 * `php artisan publishing:start {channel}` (see
 * StartPublishingSchedulerCommand); from then on it keeps itself alive
 * forever via the `finally` block below, regardless of whether a candidate
 * was found or the attempt succeeded.
 *
 * Exactly one chain runs per channel, enforced by
 * `telegram_channels.publish_chain_token`: every run carries the token it
 * was dispatched with and exits *without* rescheduling once that token no
 * longer matches the channel's current one. Chains fork more easily than
 * you'd expect — force-killing a worker mid-job leaves the job reserved,
 * and Laravel re-runs it after `retry_after`, so the interrupted run's
 * `finally` reschedules a second chain alongside the one it had already
 * dispatched (observed: three concurrent chains after two worker restarts,
 * which would have tripled the posting rate). Re-running `publishing:start`
 * is therefore safe and idempotent — it mints a new token, and every older
 * chain retires itself on its next run.
 *
 * Cadence: `max_publish_interval_minutes` (default 120 = 2h) is the
 * baseline — with nothing else waiting, that's the wait before the next
 * check. With a backlog of other ready candidates, the wait becomes random
 * between `min_publish_interval_minutes` (default 15) and a ceiling that
 * shrinks from the baseline down toward the minimum as the backlog grows,
 * saturating fully at `publish_backlog_saturation_count` (default 5)
 * candidates — i.e. "2 hours normally, but faster, randomly, the more good
 * news there is waiting." All four are per-channel `rules` (see
 * TelegramChannel::rule()), same as every other publishing rule.
 */
class PublishNextReadyNewsItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Laravel's own retry/backoff is deliberately not used here — the
    // `finally` block is the only thing that keeps the chain alive, so a
    // failed attempt must not multiply into extra parallel chains via retry.
    public int $tries = 1;

    public function __construct(
        public readonly int $telegramChannelId,
        public readonly string $chainToken,
    ) {
        $this->onQueue(config('media.queues.selection'));
    }

    public static function startChain(TelegramChannel $channel): string
    {
        $token = (string) Str::uuid();
        $channel->forceFill(['publish_chain_token' => $token])->save();

        self::dispatch($channel->id, $token);

        return $token;
    }

    public function handle(): void
    {
        $channel = TelegramChannel::find($this->telegramChannelId);

        if ($channel && $channel->publish_chain_token !== $this->chainToken) {
            // A newer chain owns this channel — retire quietly, without
            // rescheduling, so forked chains collapse back to one.
            Log::info("[publishing-scheduler] channel={$channel->id} stale chain retired");

            return;
        }

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

    private function eligibleQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return NewsItem::query()
            ->whereNotNull('media_analysis_completed_at')
            ->whereNull('publish_queued_at')
            ->whereNull('telegram_published_at');
    }

    private function pickBestCandidate(): ?NewsItem
    {
        return $this->eligibleQuery()
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
        $backlogCount = $this->eligibleQuery()->count();

        $delaySeconds = $this->computeDelaySeconds($minMinutes, $maxMinutes, $backlogCount, $channel);

        self::dispatch($channel->id, $this->chainToken)->delay(now()->addSeconds($delaySeconds));

        Log::info("[publishing-scheduler] channel={$channel->id} backlog={$backlogCount} next check in {$delaySeconds}s");
    }

    /**
     * No backlog: exactly the baseline (2h default) — a steady drip when
     * supply is scarce. With a backlog, the ceiling shrinks from the
     * baseline toward the floor as the backlog grows (fully saturated at
     * `publish_backlog_saturation_count` candidates), and the actual delay
     * is random within [floor, ceiling] — faster on average with more good
     * news waiting, but never faster than the floor and never a fixed value.
     */
    private function computeDelaySeconds(int $minMinutes, int $maxMinutes, int $backlogCount, TelegramChannel $channel): int
    {
        if ($backlogCount <= 0) {
            return $maxMinutes * 60;
        }

        $saturationCount = max(1, (int) $channel->rule('publish_backlog_saturation_count', 5));
        $saturation = min(1.0, $backlogCount / $saturationCount);
        $effectiveMaxMinutes = max($minMinutes, (int) round($maxMinutes - $saturation * ($maxMinutes - $minMinutes)));

        return random_int($minMinutes * 60, $effectiveMaxMinutes * 60);
    }
}
