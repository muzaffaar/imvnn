<?php

namespace Tests\Unit;

use App\Services\News\PublishDateParser;
use Tests\TestCase;

/**
 * The single guard between a sloppy date string and a year-old article going
 * out as today's news. Shared by the HTML extractor and the RSS fetcher, which
 * is why it is tested on its own rather than only through them.
 */
class PublishDateParserTest extends TestCase
{
    private function parse(?string $value): ?string
    {
        return app(PublishDateParser::class)->parse($value)?->toDateString();
    }

    public function test_a_date_with_no_year_is_refused(): void
    {
        // Carbon would return this year, inventing freshness. These are the
        // shapes real pages ship: Squarespace's `datetime="Sep 10"` is what
        // published a September 2025 article in September 2026.
        foreach (['Sep 10', 'September 10', '10 September', 'Sep 10, ', '03-15'] as $value) {
            $this->assertNull($this->parse($value), $value);
        }
    }

    public function test_relative_phrasings_are_refused(): void
    {
        // Meaningful only against a render time we do not have.
        foreach (['2 days ago', 'yesterday', 'today', 'an hour ago', 'last Tuesday'] as $value) {
            $this->assertNull($this->parse($value), $value);
        }
    }

    public function test_empty_and_null_are_refused(): void
    {
        $this->assertNull($this->parse(null));
        $this->assertNull($this->parse(''));
        $this->assertNull($this->parse('   '));
    }

    public function test_iso_timestamps_are_accepted(): void
    {
        $this->assertSame('2025-09-10', $this->parse('2025-09-10T14:07:07+0000'));
        $this->assertSame('2026-09-11', $this->parse('2026-09-11T06:30:00+00:00'));
    }

    public function test_rfc_2822_feed_dates_are_accepted(): void
    {
        // The normal shape of an RSS pubDate.
        $this->assertSame('2026-09-10', $this->parse('Thu, 10 Sep 2026 12:00:00 GMT'));
    }

    public function test_a_contradicting_weekday_does_not_shift_the_date(): void
    {
        // PHP walks forward to the next matching weekday instead of complaining:
        // 10 September 2026 is a Thursday, so `Wed, 10 Sep 2026` parsed as the
        // 16th until the weekday was dropped. Six days is enough to carry a
        // stale article into the fresh window.
        $this->assertSame('2026-09-10', $this->parse('Wed, 10 Sep 2026 12:00:00 GMT'));
        $this->assertSame('2026-09-10', $this->parse('Thu, 10 Sep 2026 12:00:00 GMT'));
        $this->assertSame('2026-09-10', $this->parse('10 Sep 2026 12:00:00 GMT'));
    }

    public function test_written_dates_that_state_a_year_are_accepted(): void
    {
        $this->assertSame('2026-09-11', $this->parse('Sep 11, 2026'));
        $this->assertSame('2026-09-11', $this->parse('11 September 2026'));
    }

    public function test_an_offset_date_is_converted_not_stripped(): void
    {
        // Eloquent binds a DateTimeInterface by formatting it as-is, without
        // converting the timezone, so a date in any other zone was stored as its
        // own wall-clock reading. The AWS blog publishes with `-08:00`: this
        // timestamp is really 21:58 UTC and was being saved as 13:58, eight
        // hours early, which pushed today's news out of the window.
        config(['app.timezone' => 'UTC']);

        $parsed = app(PublishDateParser::class)->parse('2026-09-10T13:58:09-08:00');

        $this->assertSame('UTC', $parsed->tzName);
        $this->assertSame('2026-09-10 21:58:09', $parsed->toDateTimeString());
    }

    public function test_a_positive_offset_is_also_converted(): void
    {
        // The error runs both ways: stored as-is, this would read five hours
        // late and could carry yesterday's article into today.
        config(['app.timezone' => 'UTC']);

        $parsed = app(PublishDateParser::class)->parse('2026-09-11T02:00:00+05:00');

        $this->assertSame('2026-09-10 21:00:00', $parsed->toDateTimeString());
    }

    public function test_an_offset_date_is_judged_fresh_on_the_audiences_day(): void
    {
        // The whole point of the normalisation: 21:58 UTC on the 10th is early
        // on the 11th in Tashkent, so this is today's news and must publish.
        config([
            'news_sources.freshness.only_today' => true,
            'news_sources.freshness.max_age_hours' => null,
            'news_sources.freshness.timezone' => 'UTC',
        ]);
        $parser = app(PublishDateParser::class);
        $policy = app(\App\Services\News\FreshnessPolicy::class);

        config(['app.timezone' => 'UTC']);
        $published = now('UTC')->startOfDay()->addHours(3);
        $asOffsetString = $published->copy()->setTimezone('-08:00')->toIso8601String();

        $this->assertTrue($policy->isFresh($parser->parse($asOffsetString)));
    }

    public function test_dates_are_converted_into_the_storage_timezone(): void
    {
        // Timestamps are stored in the application timezone, so the parser has to
        // hand back that zone: Eloquent formats a DateTimeInterface as-is, so a
        // value in any other zone is written as its own wall clock and read back
        // hours out.
        config(['app.timezone' => 'Asia/Tashkent']);

        $parsed = app(PublishDateParser::class)->parse('2026-09-10T21:58:09+00:00');

        $this->assertSame('Asia/Tashkent', $parsed->tzName);
        $this->assertSame('2026-09-11 02:58:09', $parsed->toDateTimeString());
    }

    public function test_the_storage_timezone_is_followed_rather_than_hard_coded(): void
    {
        config(['app.timezone' => 'UTC']);

        $parsed = app(PublishDateParser::class)->parse('2026-09-11T02:58:09+05:00');

        $this->assertSame('UTC', $parsed->tzName);
        $this->assertSame('2026-09-10 21:58:09', $parsed->toDateTimeString());
    }

    public function test_absurdly_old_dates_are_refused(): void
    {
        // A stray number sequence from the visible-text fallback can parse into
        // something like this.
        $this->assertNull($this->parse('1899-01-01'));
    }

    public function test_far_future_dates_are_refused(): void
    {
        $this->assertNull($this->parse('2099-01-01'));
        $this->assertNull($this->parse(now()->addDays(30)->toIso8601String()));
    }

    public function test_a_date_slightly_in_the_future_is_tolerated(): void
    {
        // Publishers routinely stamp an article a few minutes ahead, and clocks
        // drift. Rejecting those would discard the freshest news of all.
        $this->assertNotNull($this->parse(now()->addHours(6)->toIso8601String()));
    }

    public function test_a_four_digit_number_that_is_not_a_year_does_not_smuggle_a_date_through(): void
    {
        // The year check only admits 19xx/20xx, so a bare measurement cannot
        // satisfy it on its own.
        $this->assertNull($this->parse('Sep 10, 4500'));
    }
}
