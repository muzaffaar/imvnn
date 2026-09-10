<?php

namespace App\Services\Media\Scoring;

use App\DTOs\ExtractedMedia;
use Illuminate\Support\Str;

/**
 * Cheap, rule-based relevance scoring — no network calls, no ML — run on every
 * extracted candidate immediately. Feeds MediaIngestionPolicy's ordering and
 * gates which candidates are even worth an expensive vision-model pass later
 * (see RelevanceAnalysisService, VisionRelevanceAnalyzerInterface).
 */
class MediaRelevanceScorer
{
    /** Signals a "definitely the lead image" hint from a trusted extractor. */
    private const HIGH_TRUST_EXTRACTORS = ['og_meta', 'rss_enclosure', 'api_payload', 'json_ld'];

    private const MEDIUM_TRUST_EXTRACTORS = ['rss_media_content', 'html_picture', 'html_img', 'twitter_card'];

    public function scoreExtracted(ExtractedMedia $media, string $articleTitle, int $totalCandidates): float
    {
        $weights = config('media.relevance');

        $keywordScore = $this->keywordOverlap($articleTitle, trim(($media->caption ?? '').' '.($media->altText ?? '')));
        $positionScore = $this->positionScore($media->position, $totalCandidates);
        $extractorScore = $this->extractorTrust($media->extractedBy, $media->isFeaturedHint);

        $score = $weights['keyword_overlap_weight'] * $keywordScore
            + $weights['position_weight'] * $positionScore
            + $weights['extractor_source_weight'] * $extractorScore;

        return round(min(1.0, max(0.0, $score)), 4);
    }

    private function keywordOverlap(string $title, string $text): float
    {
        if ($text === '') {
            return 0.2; // no caption/alt at all is a mild negative signal, not disqualifying
        }

        $titleWords = $this->tokenize($title);
        $textWords = $this->tokenize($text);

        if (empty($titleWords) || empty($textWords)) {
            return 0.2;
        }

        $overlap = count(array_intersect($titleWords, $textWords));

        return min(1.0, $overlap / max(3, count($titleWords)));
    }

    /** @return list<string> */
    private function tokenize(string $text): array
    {
        $stopwords = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'is', 'with', 'at', 'by'];
        $words = preg_split('/[^\p{L}\p{N}]+/u', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_diff($words, $stopwords));
    }

    private function positionScore(int $position, int $totalCandidates): float
    {
        if ($totalCandidates <= 1) {
            return 1.0;
        }

        return 1.0 - ($position / $totalCandidates);
    }

    private function extractorTrust(string $extractedBy, bool $isFeaturedHint): float
    {
        if ($isFeaturedHint) {
            return 1.0;
        }

        if (in_array($extractedBy, self::HIGH_TRUST_EXTRACTORS, true)) {
            return 0.85;
        }

        if (in_array($extractedBy, self::MEDIUM_TRUST_EXTRACTORS, true)) {
            return 0.6;
        }

        return 0.4; // html_iframe, rss_media_thumbnail, generic fallbacks
    }
}
