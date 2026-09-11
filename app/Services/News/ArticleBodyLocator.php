<?php

namespace App\Services\News;

/**
 * Finds the one element on a page that holds the article's own body text.
 *
 * "The first `<article>` tag" is not good enough, and that is the whole reason
 * this exists. Related-article cards are themselves marked up as `<article>` on
 * several of the configured sources — one Hugging Face page carries 34 of them,
 * one MIT page 11 — so taking the first match can land on a teaser card rather
 * than the story. Ranking by paragraph *density* instead picks the element that
 * actually contains prose: a card has a headline and a date, the article has
 * paragraphs.
 *
 * Density is text length inside `<p>` divided by descendant element count, not
 * raw text length. Raw length would almost always favour some outer wrapper
 * (`<body>`, a layout `<div>`) that contains the article plus the navigation,
 * sidebar and footer around it.
 */
class ArticleBodyLocator
{
    /** Below this much paragraph text, a candidate is a card or a caption, not a body. */
    private const MIN_PARAGRAPH_TEXT = 200;

    public function locate(\DOMXPath $xpath): ?\DOMElement
    {
        // Semantic containers first: when a page marks up its article at all,
        // that markup is more trustworthy than any density comparison against
        // arbitrary divs.
        return $this->densest($xpath, '//article | //main')
            ?? $this->densest($xpath, '//div[.//p] | //section[.//p]');
    }

    private function densest(\DOMXPath $xpath, string $query): ?\DOMElement
    {
        $best = null;
        $bestDensity = 0.0;

        foreach ($xpath->query($query) ?: [] as $candidate) {
            if (! $candidate instanceof \DOMElement) {
                continue;
            }

            $textLength = 0;
            foreach ($candidate->getElementsByTagName('p') as $paragraph) {
                $textLength += mb_strlen($paragraph->textContent);
            }

            if ($textLength < self::MIN_PARAGRAPH_TEXT) {
                continue;
            }

            $density = $textLength / (1 + $candidate->getElementsByTagName('*')->length);

            if ($density > $bestDensity) {
                $bestDensity = $density;
                $best = $candidate;
            }
        }

        return $best;
    }
}
