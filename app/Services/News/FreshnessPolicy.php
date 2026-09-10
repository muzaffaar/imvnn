<?php

namespace App\Services\News;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * "Only today's news." Applied at two separate points, because they answer
 * different questions:
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
        return (bool) config('news_sources.freshness.only_today', true);
    }

    public function timezone(): string
    {
        return (string) config('news_sources.freshness.timezone', 'Asia/Tashkent');
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
        return CarbonImmutable::now($this->timezone())->startOfDay()->utc();
    }

    public function windowEnd(): CarbonImmutable
    {
        return $this->windowStart()->addDay();
    }
}
