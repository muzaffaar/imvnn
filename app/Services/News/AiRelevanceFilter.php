<?php

namespace App\Services\News;

/**
 * Cheap, deterministic "is this article AI-related" gate, applied twice per
 * candidate: a prefilter on whatever cheap text is available before spending
 * an HTTP fetch on the full article page, and an authoritative pass on the
 * fully parsed title+content before a NewsItem is actually created.
 *
 * Passes everything when the topic filter is off — see TopicPolicy.
 */
class AiRelevanceFilter
{
    private ?string $pattern = null;

    public function __construct(private readonly TopicPolicy $topicPolicy) {}

    public function isRelevant(string $text): bool
    {
        // Topic filtering off: every candidate is on-topic by definition. This
        // is checked before the empty-text guard on purpose — with no topic
        // restriction there is nothing for a missing prefilter text to fail.
        if (! $this->topicPolicy->enabled()) {
            return true;
        }

        if (trim($text) === '') {
            return false;
        }

        return (bool) preg_match($this->pattern(), $text);
    }

    private function pattern(): string
    {
        if ($this->pattern !== null) {
            return $this->pattern;
        }

        $keywords = config('news_sources.ai_keywords', []);

        $escaped = array_map(function (string $keyword) {
            $words = preg_split('/\s+/', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);

            return implode('\s+', array_map(fn (string $w) => preg_quote($w, '/'), $words));
        }, $keywords);

        return $this->pattern = '/\b('.implode('|', $escaped).')\b/iu';
    }
}
