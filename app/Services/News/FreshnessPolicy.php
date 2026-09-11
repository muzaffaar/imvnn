<?php

namespace App\Services\News;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How old an article may be and still be worth posting. Two policies:
 * `max_age_hours`, a rolling lookback that wins whenever it is set, and
 * `only_today`, the calendar-day default described below.
 *
 * Applied at two separate points, because they answer different questions:
 *
 *   1. Ingestion (NewsIngestionService) — don't even create a NewsItem for
 *      an article published on an earlier day. Feeds routinely carry months
 *      of backlog: the newest 20 items of a low-volume company blog can
 *      still reach back a quarter, which is how a May article ended up
 *      posted in September.
 *   2. Publishing (PublishNextReadyNewsItemJob) — don't post an article
 *      once its day has passed. Anything ingested today but not published
 *      before midnight is deliberately abandoned rather than carried over,
 *      per "if we did not manage to post it the same day, leave it unposted".
 *
 * "Today" is the calendar day in the *audience's* timezone, not UTC and not
 * the publisher's — a post is judged by the day its readers are living in.
 * The rolling lookback has no such notion and works purely in UTC, since
 * "within the last N hours" is the same instant everywhere.
 *
 * An article with no determinable publish date is not fresh. That is a
 * deliberate bias toward silence: an unknown date is far more often an old
 * article than a new one, and posting a stale item is the failure mode this
 * whole policy exists to prevent.
 */
class FreshnessPolicy
{
    public function enabled(): bool
    {
        return $this->maxAgeHours() !== null
            || (bool) config('news_sources.freshness.only_today', true);
    }

    public function timezone(): string
    {
        return (string) config('news_sources.freshness.timezone', 'Asia/Tashkent');
    }

    /**
     * Rolling lookback in hours, or null when the calendar-day policy applies.
     * A configured value of zero or less means "not set" rather than "nothing
     * is ever fresh", so an empty or malformed env value can never silence
     * the channel outright.
     */
    public function maxAgeHours(): ?int
    {
        $configured = config('news_sources.freshness.max_age_hours');

        if ($configured === null || $configured === '') {
            return null;
        }

        $hours = (int) $configured;

        return $hours > 0 ? $hours : null;
    }

    public function isFresh(?CarbonInterface $publishedAt): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if ($publishedAt === null) {
            return false;
        }

        return $publishedAt >= $this->windowStart() && $publishedAt < $this->windowEnd();
    }

    /**
     * Start of today in the audience timezone, returned **in UTC**.
     *
     * The conversion is load-bearing, not cosmetic: Eloquent binds a
     * DateTimeInterface to SQL by formatting it as-is, without converting
     * the timezone, so handing back a +05:00 Carbon would compare
     * "2026-09-10 00:00" against UTC-stored timestamps and silently discard
     * everything published between 19:00 and 24:00 UTC — the first five
     * hours of the Tashkent day, every day.
     */
    public function windowStart(): CarbonImmutable
    {
        if ($hours = $this->maxAgeHours()) {
            return CarbonImmutable::now('UTC')->subHours($hours);
        }

        return CarbonImmutable::now($this->timezone())->startOfDay()->utc();
    }

    /**
     * With a rolling lookback, the window stays open an hour past now rather
     * than ending exactly at it: publishers routinely stamp an article a few
     * minutes into the future, and clocks drift. Without that margin a
     * genuinely brand-new article — the most valuable kind here — would be
     * the one thing the filter rejected.
     */
    public function windowEnd(): CarbonImmutable
    {
        if ($this->maxAgeHours()) {
            return CarbonImmutable::now('UTC')->addHour();
        }

        return CarbonImmutable::now($this->timezone())->startOfDay()->utc()->addDay();
    }
}
