<?php

namespace Tests\Feature;

use App\Jobs\Media\AnalyzeMediaForNewsItemJob;
use App\Jobs\Media\ExtractMediaJob;
use App\Models\NewsItem;
use App\Models\Source;
use App\Services\Media\MediaPipelineProgressTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * An article becomes publishable only once `media_analysis_completed_at` is
 * set — PublishNextReadyNewsItemJob selects from nothing else. Every way the
 * media pipeline can give up must therefore still reach that timestamp, or the
 * article is stranded invisibly: no failed job to retry, no row to notice, and
 * a channel that quietly posts less than it should.
 */
class PublishCandidateRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function newsItem(): NewsItem
    {
        $source = Source::create(['name' => 'Test', 'slug' => 'test', 'base_url' => 'https://example.com', 'type' => 'rss']);

        return NewsItem::create([
            'source_id' => $source->id,
            'title' => 'Story',
            'url' => 'https://example.com/story',
            'published_at' => now(),
        ]);
    }

    public function test_extraction_failure_still_queues_analysis_so_the_article_can_post_as_text(): void
    {
        $news = $this->newsItem();
        Queue::fake();

        (new ExtractMediaJob($news->id))->failed(new RuntimeException('extractor exploded'));

        Queue::assertPushed(AnalyzeMediaForNewsItemJob::class,
            fn (AnalyzeMediaForNewsItemJob $job) => $job->newsItemId === $news->id);
    }

    public function test_analysis_failure_still_marks_the_article_publishable(): void
    {
        $news = $this->newsItem();
        $this->assertNull($news->media_analysis_completed_at);

        (new AnalyzeMediaForNewsItemJob($news->id))->failed(new RuntimeException('scoring exploded'));

        $this->assertNotNull($news->fresh()->media_analysis_completed_at);
    }

    public function test_analysis_failure_does_not_disturb_an_already_published_article(): void
    {
        $news = $this->newsItem();
        $completedAt = now()->subHour();
        NewsItem::whereKey($news->id)->update([
            'media_analysis_completed_at' => $completedAt,
            'telegram_published_at' => now(),
        ]);

        (new AnalyzeMediaForNewsItemJob($news->id))->failed(new RuntimeException('scoring exploded'));

        $this->assertSame(
            $completedAt->toDateTimeString(),
            $news->fresh()->media_analysis_completed_at->toDateTimeString(),
        );
    }

    public function test_a_lost_progress_counter_is_treated_as_the_final_job(): void
    {
        $tracker = app(MediaPipelineProgressTracker::class);
        $news = $this->newsItem();

        // The counter lives in a cache: an expiry, a flush or a restarted Redis
        // erases it while downloads are still in flight. Reporting "not
        // finished" then means analysis is never dispatched at all.
        $tracker->start($news->id, 3);
        Cache::flush();

        $this->assertTrue($tracker->complete($news->id));
    }

    public function test_progress_counter_still_waits_for_outstanding_jobs(): void
    {
        $tracker = app(MediaPipelineProgressTracker::class);
        $news = $this->newsItem();

        $tracker->start($news->id, 3);

        $this->assertFalse($tracker->complete($news->id));
        $this->assertFalse($tracker->complete($news->id));
        $this->assertTrue($tracker->complete($news->id));
    }
}
