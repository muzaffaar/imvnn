<?php

namespace App\Jobs\Telegram;

use App\Enums\MediaStatus;
use App\Jobs\Media\SelectMediaForPublishingJob;
use App\Models\NewsItem;
use App\Models\TelegramChannel;
use App\Services\News\FreshnessPolicy;
use App\Services\News\NewsPriorityScorer;
use App\Support\Observability\PipelineLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
 * Cadence: `max_publish_interval_minutes` is the baseline — with nothing else
 * waiting, that's the wait before the next check. With a backlog, the wait
 * becomes random between `min_publish_interval_minutes` and a ceiling set by
 * whichever pacing rule is tighter, so the channel is faster the more good news
 * is waiting and never posts on a robotic fixed beat.
 *
 * The ceiling is normally the *deadline*: the time left in today's freshness
 * window divided across the articles still waiting. Under "only today's news"
 * an article that misses midnight is abandoned, so a fixed interval silently
 * discards whatever the day could not fit. Pacing to the deadline means the
 * channel speeds up because the day is ending, which is what keeps the day's
 * news on the day it belongs to. Where there is no shared deadline — the
 * rolling-lookback policy, or freshness off — the ceiling instead shrinks from
 * the baseline toward the floor as the backlog grows, saturating at
 * `publish_backlog_saturation_count`.
 *
 * Every rule named here is a per-channel `rules` entry (see
 * TelegramChannel::rule()), as is `pace_to_end_of_day`, which turns the
 * deadline rule off for a channel that would rather keep a steady cadence than
 * publish everything. See computeDelaySeconds().
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

        PipelineLogger::info('telegram.scheduler_started', ['telegram_channel_id' => $channel->id]);

        return $token;
    }

    public function handle(): void
    {
        $channel = TelegramChannel::find($this->telegramChannelId);

        if ($channel && $channel->publish_chain_token !== $this->chainToken) {
            // A newer chain owns this channel — retire quietly, without
            // rescheduling, so forked chains collapse back to one.
            PipelineLogger::info('telegram.scheduler_stale_chain_retired', ['telegram_channel_id' => $channel->id]);

            return;
        }

        try {
            if (! $channel || ! $channel->is_active) {
                PipelineLogger::warning('telegram.scheduler_skipped', [
                    'telegram_channel_id' => $this->telegramChannelId,
                    'reason' => $channel ? 'channel_inactive' : 'channel_not_found',
                ]);

                return;
            }

            $candidate = $this->pickBestCandidate($channel);

            if ($candidate && $this->claim($candidate)) {
                SelectMediaForPublishingJob::dispatch($candidate->id, $channel->id);
                PipelineLogger::info('telegram.scheduler_candidate_queued', [
                    'telegram_channel_id' => $channel->id,
                    'news_item_id' => $candidate->id,
                    'best_quality_score' => $candidate->best_quality_score,
                    'news_priority_score' => $candidate->news_priority_score,
                ]);
            } elseif ($candidate) {
                PipelineLogger::warning('telegram.scheduler_claim_lost', [
                    'telegram_channel_id' => $channel->id,
                    'news_item_id' => $candidate->id,
                ]);
            } else {
                PipelineLogger::debug('telegram.scheduler_no_candidate', ['telegram_channel_id' => $channel->id]);
            }
        } finally {
            $this->reschedule($channel);
        }
    }

    private function eligibleQuery(): Builder
    {
        $query = NewsItem::query()
            ->whereNotNull('media_analysis_completed_at')
            ->whereNull('publish_queued_at')
            ->whereNull('telegram_published_at');

        // Exclude anything this channel has already sent, judged by the
        // article's canonical URL rather than by this row's own history. A
        // re-ingested article is a brand new row with `telegram_published_at`
        // empty, so the three checks above cannot see that it is already in the
        // channel. Applied here as well as in PublishToTelegramJob so the
        // backlog count — which drives the posting cadence — stays honest.
        $query->whereNotExists(function ($sub) {
            $sub->selectRaw(1)
                ->from('published_posts')
                ->where('published_posts.telegram_channel_id', $this->telegramChannelId)
                ->whereColumn('published_posts.canonical_url', 'news_items.canonical_url');
        });

        // Only today's news, and only today — an article whose day has passed
        // is abandoned rather than carried over, so the channel never wakes up
        // to yesterday's headlines. This also keeps the backlog count honest,
        // since that count drives the posting cadence.
        $freshness = app(FreshnessPolicy::class);

        if ($freshness->enabled()) {
            $query->where('published_at', '>=', $freshness->windowStart())
                ->where('published_at', '<', $freshness->windowEnd());
        }

        return $query;
    }

    private function pickBestCandidate(TelegramChannel $channel): ?NewsItem
    {
        $priorityScorer = app(NewsPriorityScorer::class);
        $minimumPriority = max(0.0, min(1.0, (float) $channel->rule('min_news_priority_score', 0.20)));

        $candidates = $this->eligibleQuery()
            ->withMax(['mediaAssets as best_quality_score' => function ($query) {
                $query->where('status', MediaStatus::Ready->value);
            }], 'quality_score')
            ->limit((int) config('news_sources.publication_priority.candidate_limit', 100))
            ->get()
            ->map(function (NewsItem $candidate) use ($priorityScorer): NewsItem {
                $candidate->setAttribute('news_priority_score', $priorityScorer->score($candidate));

                return $candidate;
            })
            ->filter(fn (NewsItem $candidate) => $candidate->news_priority_score >= $minimumPriority)
            ->sort(function (NewsItem $left, NewsItem $right): int {
                // A high-impact news signal comes first. Media quality is the
                // tie-breaker; NULL ranks behind usable media. Older ready work
                // then wins to avoid starvation among otherwise equal articles.
                $priority = $right->news_priority_score <=> $left->news_priority_score;
                if ($priority !== 0) {
                    return $priority;
                }

                $quality = ($right->best_quality_score ?? -1) <=> ($left->best_quality_score ?? -1);
                if ($quality !== 0) {
                    return $quality;
                }

                return ($left->media_analysis_completed_at?->getTimestamp() ?? PHP_INT_MAX)
                    <=> ($right->media_analysis_completed_at?->getTimestamp() ?? PHP_INT_MAX);
            });

        return $candidates->first();
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
        $maxMinutes = (int) $channel->rule('max_publish_interval_minutes', 30);
        $backlogCount = $this->eligibleQuery()->count();
        $secondsLeftToday = $this->secondsLeftInWindow();

        $delaySeconds = $this->computeDelaySeconds($minMinutes, $maxMinutes, $backlogCount, $channel);

        self::dispatch($channel->id, $this->chainToken)->delay(now()->addSeconds($delaySeconds));

        PipelineLogger::info('telegram.scheduler_rescheduled', [
            'telegram_channel_id' => $channel->id,
            'backlog_count' => $backlogCount,
            'delay_seconds' => $delaySeconds,
            'seconds_left_today' => $secondsLeftToday,
            'channel_active' => $channel->is_active,
        ]);

        $this->warnIfBacklogCannotClear($channel, $backlogCount, $minMinutes, $secondsLeftToday);
    }

    /**
     * Says out loud when the day's remaining articles cannot all be posted
     * before the window closes, even at the floor interval.
     *
     * Worth a warning rather than silence: the articles are simply abandoned at
     * midnight, which looks identical to "there was no news". The fix is an
     * operator decision — lower `--min-interval`, or accept the loss — so the
     * numbers needed to make it are logged here.
     */
    private function warnIfBacklogCannotClear(
        TelegramChannel $channel,
        int $backlogCount,
        int $minMinutes,
        ?int $secondsLeftToday,
    ): void {
        if ($backlogCount <= 0 || $secondsLeftToday === null) {
            return;
        }

        $secondsNeeded = $backlogCount * $minMinutes * 60;

        if ($secondsNeeded <= $secondsLeftToday) {
            return;
        }

        PipelineLogger::warning('telegram.scheduler_backlog_will_expire', [
            'telegram_channel_id' => $channel->id,
            'backlog_count' => $backlogCount,
            'seconds_left_today' => $secondsLeftToday,
            'seconds_needed_at_floor' => $secondsNeeded,
            'postable_before_midnight' => intdiv($secondsLeftToday, max(1, $minMinutes * 60)),
            'min_publish_interval_minutes' => $minMinutes,
        ]);
    }

    /**
     * Seconds until today's freshness window closes, or null when there is no
     * shared deadline to pace against.
     *
     * Null for the rolling-lookback policy (`max_age_hours`), where each article
     * expires on its own clock rather than all of them at midnight, and null
     * when freshness is off entirely and nothing expires at all.
     */
    private function secondsLeftInWindow(): ?int
    {
        $freshness = app(FreshnessPolicy::class);

        if (! $freshness->enabled() || $freshness->maxAgeHours() !== null) {
            return null;
        }

        $seconds = (int) now()->diffInSeconds($freshness->windowEnd(), false);

        return $seconds > 0 ? $seconds : 0;
    }

    /**
     * How long to wait before looking for the next article.
     *
     * No backlog: exactly the baseline — a steady drip when supply is scarce.
     *
     * With a backlog, the ceiling comes from whichever of two rules is tighter,
     * and the actual delay is random within [floor, ceiling] so the channel
     * never posts on a robotic cadence:
     *
     *  - **Deadline pacing** (the important one): spread the articles still
     *    waiting across the time left in today's window. Under the "only
     *    today's news" policy an article that misses midnight is abandoned, so
     *    a fixed interval quietly throws away whatever the day could not fit —
     *    ten articles at five minutes each needs fifty minutes, and starting at
     *    23:30 loses four of them. Dividing the remaining time by the backlog
     *    makes the channel finish the day's news *because* the day is ending.
     *
     *  - **Backlog saturation** (the pre-existing rule): the ceiling shrinks
     *    from the baseline toward the floor as the backlog grows. This is what
     *    governs when there is no deadline to pace against — the rolling
     *    lookback policy, or freshness switched off.
     *
     * Arrival rate needs no separate measurement: articles arriving faster is
     * precisely what makes the backlog grow, and both rules already read the
     * backlog. Measuring news-per-hour as well would be a second, laggier
     * estimate of the same thing, and the two could disagree.
     *
     * The floor always wins. If the backlog cannot be cleared even at the floor
     * the surplus still expires at midnight, which
     * `warnIfBacklogCannotClear()` reports rather than hiding.
     */
    private function computeDelaySeconds(int $minMinutes, int $maxMinutes, int $backlogCount, TelegramChannel $channel): int
    {
        $minSeconds = $minMinutes * 60;
        $maxSeconds = $maxMinutes * 60;

        if ($backlogCount <= 0) {
            return $maxSeconds;
        }

        $ceiling = $this->deadlineCeilingSeconds($backlogCount, $channel)
            ?? $this->saturationCeilingSeconds($minSeconds, $maxSeconds, $backlogCount, $channel);

        $ceiling = max($minSeconds, min($maxSeconds, $ceiling));

        return random_int($minSeconds, $ceiling);
    }

    /**
     * The interval that fits every waiting article into the rest of today, or
     * null when there is no shared deadline to pace against.
     *
     * Divided by `backlog + 1` rather than `backlog` so the schedule finishes
     * with one interval in hand instead of landing the final post on the stroke
     * of midnight, where any delay in the media pipeline would lose it.
     *
     * Disable per channel with the `pace_to_end_of_day` rule if a steady
     * cadence matters more than publishing everything.
     */
    private function deadlineCeilingSeconds(int $backlogCount, TelegramChannel $channel): ?int
    {
        if (! $channel->rule('pace_to_end_of_day', true)) {
            return null;
        }

        $secondsLeft = $this->secondsLeftInWindow();

        if ($secondsLeft === null || $secondsLeft <= 0) {
            return null;
        }

        return (int) floor($secondsLeft / ($backlogCount + 1));
    }

    private function saturationCeilingSeconds(int $minSeconds, int $maxSeconds, int $backlogCount, TelegramChannel $channel): int
    {
        $saturationCount = max(1, (int) $channel->rule('publish_backlog_saturation_count', 5));
        $saturation = min(1.0, $backlogCount / $saturationCount);

        return (int) round($maxSeconds - $saturation * ($maxSeconds - $minSeconds));
    }
}
