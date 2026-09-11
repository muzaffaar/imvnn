<?php

namespace App\Services\News;

use Carbon\CarbonImmutable;

/**
 * The one place a publish date is turned from text into a timestamp.
 *
 * Shared by the HTML extractor and the RSS fetcher deliberately: the rule below
 * is the only thing standing between a sloppy date string and a year-old article
 * published as today's news, and a rule that important must not exist in two
 * copies that can drift apart.
 *
 * A wrong date is far more damaging than a missing one. An article with no date
 * is simply not fresh and gets dropped (see FreshnessPolicy); an article carrying
 * an invented recent date passes every filter there is, because the value the
 * filter inspects already looks fresh. So both guards here fail closed.
 */
class PublishDateParser
{
    /** How far past now a stated date may be before it is treated as broken. */
    private const FUTURE_TOLERANCE_DAYS = 2;

    public function parse(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '' || ! $this->statesAYear($value)) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($this->withoutLeadingWeekday($value));
        } catch (\Throwable) {
            return null;
        }

        // A stray number sequence can parse into something absurd, and a bogus
        // date is worse than no date once a freshness filter depends on it.
        if ($parsed->year < 2000 || $parsed->isAfter(CarbonImmutable::now()->addDays(self::FUTURE_TOLERANCE_DAYS))) {
            return null;
        }

        return $parsed;
    }

    /**
     * Drops a leading weekday name, which is decoration in RFC 2822 and a trap
     * in PHP.
     *
     * When the weekday contradicts the date, PHP's parser does not complain — it
     * walks *forward* to the next date that matches the named weekday.
     * `Wed, 10 Sep 2026` becomes 16 September 2026, six days later, because 10
     * September 2026 is a Thursday. A feed with a careless weekday would
     * therefore have its articles silently redated by up to six days, which can
     * carry a stale article into the fresh window or push today's article into
     * the future, where the sanity bound then discards it.
     *
     * The weekday is fully implied by the rest of the string, so removing it
     * loses nothing.
     */
    private function withoutLeadingWeekday(string $value): string
    {
        return preg_replace('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*,?\s+/i', '', $value) ?? $value;
    }

    /**
     * Refuses any date string that does not state its year outright.
     *
     * Carbon fills a missing year in with the *current* one:
     * `CarbonImmutable::parse('Sep 10')` returns 10 September of this year. That
     * turns an undated fragment into a confidently recent timestamp, which is
     * the one failure a freshness filter cannot catch.
     *
     * Not hypothetical. Stability AI ships
     * `<time class="dt-published" datetime="Sep 10">` with no year, and a post
     * from 10 September 2025 was published to the channel on 11 September 2026.
     *
     * Relative phrasings ("2 days ago", "yesterday") are refused by the same
     * check, and should be: they are meaningful only against a page-render time
     * we do not have, so resolving them against now() invents freshness.
     */
    private function statesAYear(string $value): bool
    {
        return (bool) preg_match('/(?<!\d)(19|20)\d{2}(?!\d)/', $value);
    }
}
