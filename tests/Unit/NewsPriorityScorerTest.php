<?php

namespace Tests\Unit;

use App\Models\NewsItem;
use App\Services\News\NewsPriorityScorer;
use Tests\TestCase;

class NewsPriorityScorerTest extends TestCase
{
    public function test_high_impact_signals_rank_a_story_above_a_routine_update(): void
    {
        $scorer = app(NewsPriorityScorer::class);

        $highImpact = new NewsItem([
            'title' => 'World-first AI breakthrough launches today',
            'content' => 'The company announced a major release.',
        ]);
        $routine = new NewsItem([
            'title' => 'Weekly developer documentation update',
            'content' => 'Small fixes and examples are now available.',
        ]);

        $this->assertGreaterThan($scorer->score($routine), $scorer->score($highImpact));
        $this->assertGreaterThanOrEqual(0.20, $scorer->score($highImpact));
        $this->assertSame(0.0, $scorer->score($routine));
    }
}
