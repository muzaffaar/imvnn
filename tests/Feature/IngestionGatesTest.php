<?php

namespace Tests\Feature;

use App\Services\News\AiRelevanceFilter;
use App\Services\News\FreshnessPolicy;
use App\Services\News\TopicPolicy;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The two gates that decide how much news reaches the channel at all: topic
 * and freshness. Both are configuration rather than code paths, which is
 * exactly why they are worth pinning — a silent channel looks identical
 * whether the cause is a broken fetcher or a filter doing its job.
 */
class IngestionGatesTest extends TestCase
{
    private function filter(): AiRelevanceFilter
    {
        return new AiRelevanceFilter(new TopicPolicy);
    }

    public function test_keyword_filter_rejects_unrelated_text_while_topic_filter_is_on(): void
    {
        config(['news_sources.topic_filter.enabled' => true]);

        $this->assertFalse($this->filter()->isRelevant('Local bakery wins regional pastry prize'));
    }

    public function test_keyword_filter_accepts_robotics_and_machine_learning(): void
    {
        config(['news_sources.topic_filter.enabled' => true]);

        // Neither term matched before robotics/ML keywords were added, so the
        // robotics feeds in config/news_sources.php were being filtered out.
        $this->assertTrue($this->filter()->isRelevant('A humanoid robot learns to fold laundry'));
        $this->assertTrue($this->filter()->isRelevant('Reinforcement learning cuts training cost'));
    }

    public function test_topic_filter_off_accepts_anything_including_empty_text(): void
    {
        config(['news_sources.topic_filter.enabled' => false]);

        $this->assertTrue($this->filter()->isRelevant('Local bakery wins regional pastry prize'));
        $this->assertTrue($this->filter()->isRelevant(''));
    }

    public function test_lookback_window_accepts_yesterday_which_only_today_rejects(): void
    {
        $yesterday = CarbonImmutable::now('UTC')->subHours(20);

        config([
            'news_sources.freshness.only_today' => true,
            'news_sources.freshness.max_age_hours' => null,
            'news_sources.freshness.timezone' => 'UTC',
        ]);
        $this->assertFalse((new FreshnessPolicy)->isFresh(CarbonImmutable::now('UTC')->startOfDay()->subHour()));

        config(['news_sources.freshness.max_age_hours' => 72]);
        $this->assertTrue((new FreshnessPolicy)->isFresh($yesterday));
    }

    public function test_lookback_window_still_rejects_genuinely_stale_news(): void
    {
        config(['news_sources.freshness.max_age_hours' => 72]);

        $this->assertFalse((new FreshnessPolicy)->isFresh(CarbonImmutable::now('UTC')->subDays(30)));
    }

    public function test_lookback_window_tolerates_a_slightly_future_publish_date(): void
    {
        config(['news_sources.freshness.max_age_hours' => 72]);

        // Publishers routinely stamp an article a few minutes ahead. Rejecting
        // those would discard the freshest news of all.
        $this->assertTrue((new FreshnessPolicy)->isFresh(CarbonImmutable::now('UTC')->addMinutes(5)));
    }

    public function test_unusable_lookback_value_falls_back_to_the_day_policy(): void
    {
        // An empty or zero env value must not mean "nothing is ever fresh",
        // which would silence the channel completely.
        config([
            'news_sources.freshness.only_today' => true,
            'news_sources.freshness.timezone' => 'UTC',
        ]);

        foreach (['', 0, '0', null] as $value) {
            config(['news_sources.freshness.max_age_hours' => $value]);
            $policy = new FreshnessPolicy;

            $this->assertNull($policy->maxAgeHours());
            $this->assertTrue($policy->isFresh(CarbonImmutable::now('UTC')));
        }
    }

    public function test_the_day_window_is_expressed_in_the_storage_timezone(): void
    {
        // Both halves of the comparison must share a zone, because Eloquent
        // binds a boundary as-is. A window in the wrong zone does not fail, it
        // silently slides by the offset.
        config([
            'app.timezone' => 'Asia/Tashkent',
            'app.storage_timezone' => 'Asia/Tashkent',
            'news_sources.freshness.only_today' => true,
            'news_sources.freshness.max_age_hours' => null,
            'news_sources.freshness.timezone' => 'Asia/Tashkent',
        ]);
        $policy = new FreshnessPolicy;

        $this->assertSame('Asia/Tashkent', $policy->windowStart()->tzName);
        $this->assertSame('00:00:00', $policy->windowStart()->format('H:i:s'));
        $this->assertSame('00:00:00', $policy->windowEnd()->format('H:i:s'));
        $this->assertSame(24, (int) $policy->windowStart()->diffInHours($policy->windowEnd()));
    }

    public function test_an_article_published_early_in_the_audience_day_is_fresh(): void
    {
        // The case the old UTC boundary discarded: the first hours of the
        // Tashkent day fell before a UTC-midnight window start.
        config([
            'app.timezone' => 'Asia/Tashkent',
            'app.storage_timezone' => 'Asia/Tashkent',
            'news_sources.freshness.only_today' => true,
            'news_sources.freshness.max_age_hours' => null,
            'news_sources.freshness.timezone' => 'Asia/Tashkent',
        ]);

        $earlyToday = CarbonImmutable::now('Asia/Tashkent')->startOfDay()->addHours(2);

        $this->assertTrue((new FreshnessPolicy)->isFresh($earlyToday));
    }

    public function test_freshness_can_be_switched_off_entirely(): void
    {
        config([
            'news_sources.freshness.only_today' => false,
            'news_sources.freshness.max_age_hours' => null,
        ]);
        $policy = new FreshnessPolicy;

        $this->assertFalse($policy->enabled());
        $this->assertTrue($policy->isFresh(CarbonImmutable::now('UTC')->subYears(3)));
    }

    public function test_an_undated_article_is_never_fresh_under_either_policy(): void
    {
        config(['news_sources.freshness.max_age_hours' => 72]);
        $this->assertFalse((new FreshnessPolicy)->isFresh(null));

        config(['news_sources.freshness.max_age_hours' => null, 'news_sources.freshness.only_today' => true]);
        $this->assertFalse((new FreshnessPolicy)->isFresh(null));
    }
}
