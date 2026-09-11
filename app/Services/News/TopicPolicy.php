<?php

namespace App\Services\News;

/**
 * Whether the channel restricts itself to AI, machine learning and robotics,
 * or takes whatever the sources publish.
 *
 * There are two independent topic gates in ingestion and they must agree,
 * which is the only reason this exists as its own class rather than a
 * `config()` call at each site:
 *
 *   1. The keyword prefilter (AiRelevanceFilter), which also serves as the
 *      relevance verdict on the free heuristic path.
 *   2. The AI analyzer's own `is_ai_related` judgment (AiArticleAnalyzer),
 *      applied in NewsIngestionService.
 *
 * Relaxing only one of them is the trap: letting everything past the keyword
 * prefilter while the model still answers "not AI-related" drops the same
 * articles one stage later, after paying for the fetch and the model call.
 *
 * Freshness and deduplication are deliberately unaffected — turning the topic
 * filter off widens *what subjects* qualify, never how old an article may be
 * or whether it can post twice. See FreshnessPolicy for the other half.
 */
class TopicPolicy
{
    public function enabled(): bool
    {
        return (bool) config('news_sources.topic_filter.enabled', true);
    }
}
