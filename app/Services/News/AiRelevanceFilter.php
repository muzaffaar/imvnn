<?php

namespace App\Services\News;

/**
 * Cheap, deterministic "is this article AI-related" gate, applied twice per
 * candidate: a prefilter on whatever cheap text is available before spending
 * an HTTP fetch on the full article page, and an authoritative pass on the
 * fully parsed title+content before a NewsItem is actually created.
 */
class AiRelevanceFilter
{
    private ?string $pattern = null;

    public function isRelevant(string $text): bool
    {
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
