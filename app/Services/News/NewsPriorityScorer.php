<?php

namespace App\Services\News;

use App\Models\NewsItem;

/**
 * A transparent, zero-cost priority signal for the publishing scheduler.
 *
 * It does not decide whether an article is AI-related (that remains the
 * ingestion pipeline's responsibility). It only places verified articles with
 * high-impact, timely signals ahead of routine updates without asking an LLM
 * to manufacture a "shocking" tone.
 */
final class NewsPriorityScorer
{
    public function score(NewsItem $newsItem): float
    {
        $text = mb_strtolower(trim(($newsItem->title ?? '')."\n".mb_substr(strip_tags((string) $newsItem->content), 0, 4000)));
        $score = 0.0;

        foreach (config('news_sources.publication_priority.signals', []) as $signal) {
            $pattern = $signal['pattern'] ?? null;
            $weight = $signal['weight'] ?? null;

            if (is_string($pattern) && is_numeric($weight) && preg_match($pattern, $text) === 1) {
                $score += (float) $weight;
            }
        }

        return min(1.0, round($score, 3));
    }
}
