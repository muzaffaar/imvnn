<?php

namespace Tests\Feature;

use App\Models\TelegramChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `publishing:start` is the only supported way to change a channel's posting
 * rate, so its validation is what stands between an operator and a channel
 * that either floods or goes quiet.
 */
class PublishingCadenceCommandTest extends TestCase
{
    use RefreshDatabase;

    private function channel(): TelegramChannel
    {
        return TelegramChannel::create([
            'name' => 'Test',
            'chat_id' => '@test',
            'is_active' => true,
            'rules' => TelegramChannel::defaultRules(),
        ]);
    }

    public function test_cadence_options_are_written_to_the_channel_rules(): void
    {
        Queue::fake();
        $channel = $this->channel();

        $this->artisan('publishing:start', [
            'channel' => $channel->id,
            '--min-interval' => 5,
            '--max-interval' => 5,
            '--min-priority' => 0,
        ])->assertSuccessful();

        $channel->refresh();
        $this->assertSame(5, $channel->rule('min_publish_interval_minutes'));
        $this->assertSame(5, $channel->rule('max_publish_interval_minutes'));
        // JSON storage returns a whole number as an int, so compare the way
        // PublishNextReadyNewsItemJob reads it: cast to float.
        $this->assertSame(0.0, (float) $channel->rule('min_news_priority_score'));
    }

    public function test_a_floor_above_the_baseline_is_rejected_without_touching_the_channel(): void
    {
        Queue::fake();
        $channel = $this->channel();

        $this->artisan('publishing:start', [
            'channel' => $channel->id,
            '--min-interval' => 60,
            '--max-interval' => 5,
        ])->assertFailed();

        $this->assertSame(15, $channel->fresh()->rule('min_publish_interval_minutes'));
        Queue::assertNothingPushed();
    }

    public function test_an_out_of_range_priority_is_rejected(): void
    {
        Queue::fake();
        $channel = $this->channel();

        $this->artisan('publishing:start', ['channel' => $channel->id, '--min-priority' => 5])->assertFailed();

        $this->assertSame(0.20, (float) $channel->fresh()->rule('min_news_priority_score'));
    }

    public function test_omitting_the_options_leaves_the_existing_cadence_alone(): void
    {
        Queue::fake();
        $channel = $this->channel();
        $channel->forceFill(['rules' => TelegramChannel::defaultRules(['max_publish_interval_minutes' => 45])])->save();

        $this->artisan('publishing:start', ['channel' => $channel->id])->assertSuccessful();

        $this->assertSame(45, $channel->fresh()->rule('max_publish_interval_minutes'));
    }
}
