# News Fetching Pipeline

Covers `App\Services\News`, `App\Jobs\News`, `config/news_sources.php`, and
the `sources` table's fetch-related columns. This is the stage that turns a
list of source links into `NewsItem` rows — everything after that is the
media pipeline (see [`docs/MEDIA_ARCHITECTURE.md`](MEDIA_ARCHITECTURE.md)).

## Where source links go

`config/news_sources.php` — the `sources` array. Paste links there, then:

```
php artisan news-sources:sync   # upserts into the sources table, by slug
php artisan news:fetch          # dispatch a fetch job per active source (also scheduled)
```

The config file, not the database, is the source of truth for *which* links
exist — re-running `sync` after editing it is always safe (upsert by slug).
`sources` columns beyond that (`reliability_score`, `media_reuse_permitted`)
feed directly into the media pipeline's quality scoring and copyright policy.

## Pipeline

```
config/news_sources.php  --news-sources:sync-->  sources table
                                                        │
                                          news:fetch (scheduled, or manual)
                                                        │
                                                        ▼
                                        FetchNewsSourceJob (queue: news-fetch)
                                                        │
                                    NewsSourceFetcherManager picks a fetcher:
                                    RssSourceFetcher | HtmlCrawlSourceFetcher
                                                        │
                                        one RawArticleCandidate per article
                                                        ▼
                                ProcessNewsCandidateJob (queue: news-parse, one per candidate)
                                                        │
                                              NewsIngestionService:
                                       prefilter -> fetch page (if needed) -> parse
                                       -> authoritative AI filter -> dedup -> NewsItem
                                                        │
                                          ExtractMediaJob::dispatch($newsItem->id)
                                                        ▼
                                          (media pipeline takes over)
```

## RSS vs. HTML crawl

Both are supported per-source (`sources.fetch_type`), since real source
lists mix the two:

- **`rss`** (`RssSourceFetcher`) — parses RSS 2.0 *and* Atom (detected from
  the root element), including `media:content`/`media:thumbnail`/`enclosure`
  into the exact shape `RssMediaExtractor` already expects (see
  `RawArticleCandidate::$rssItem`) — an RSS-sourced article gets full media
  extraction without the media pipeline needing to know it came from a feed.
  Title, summary/`content:encoded`, and publish date come straight from the
  feed; the full article page is still fetched afterward (see below) so the
  HTML-based extractors (`HtmlMetadataExtractor`, `HtmlContentExtractor`) have
  something to work with too.
- **`html_crawl`** (`HtmlCrawlSourceFetcher`) — for sites with no usable
  feed. Fetches one listing page (homepage or a section page) and looks for
  `<a>` links that *look like* articles, using only URL-shape heuristics:
  same host as the source (never follow off-site/syndication links), not
  under an excluded path segment (`/tag/`, `/category/`, `/author/`,
  `/search/`, etc.), and either a reasonably deep path or a long hyphenated
  slug. This is deliberately conservative — false negatives (missing a real
  article) are fine, false positives (queuing a tag/category page as an
  "article") waste a fetch and usually just get rejected by the AI filter
  anyway. Anchor text becomes the weak prefilter signal (see below); the
  crawler never tries to guess a title/body from the listing markup itself,
  since that varies too much between sites to heuristic reliably — the
  actual article page is always fetched and parsed individually.

## Article parsing (`ArticleContentExtractor`)

Not a full Readability port — deliberately simple, in the same spirit as the
media pipeline's other "cheap heuristic, not a research project" choices:

1. Title: `og:title` → `<title>` → first `<h1>`.
2. Publish date: common `<meta>` names
   (`article:published_time`, `publish-date`, ...) → `<time datetime>`.
3. Body: all `<p>` text inside `<article>` if present; otherwise the
   `<div>`/`<section>`/`<main>` with the highest *paragraph-text density*
   (text length per descendant element, not raw text length — picking by raw
   text would almost always select some outer layout wrapper that happens to
   also contain nav/footer text, since XPath's `.//p` matches at any depth).

Good enough to get a usable excerpt and a real publish date from an arbitrary
site; it will occasionally grab a mid-article paragraph as the "excerpt" on
markup it doesn't parse well (no `<article>` tag, unconventional layout) —
acceptable since this excerpt only ever becomes a Telegram caption
(`Str::limit`'d to 500 chars) and a `MediaRelevanceScorer` keyword-overlap
input, not a republished full article body.

## AI relevance filtering and analysis

Two layers, in order:

1. **Prefilter** (`AiRelevanceFilter`, always runs, free), before any HTTP
   fetch of the article page: title + summary for RSS candidates, or just
   the anchor text for `html_crawl` candidates (the only signal available
   before visiting the page). Matters most for crawled sources, where it
   avoids spending a fetch — or a Gemini call — on every discovered link.
   Matching is case-insensitive, word-boundary (`\b...\b`) regex against
   `config('news_sources.ai_keywords')` — a short phrase needs only one
   match anywhere in the text. Word boundaries matter for short keywords
   like `ai`: `\bai\b` matches "new **AI** tool" but not "s**ai**d" or
   "m**ai**ntain", since a word boundary requires an actual transition
   between a word and a non-word character.
2. **Authoritative analysis** (`ArticleAnalyzerInterface`, after the article
   page is fetched): decides the final title, content, and AI-relevance
   verdict that actually gates `NewsItem` creation. Two implementations:

   - **`HeuristicArticleAnalyzer`** (free, always succeeds): reuses
     `ArticleContentExtractor`'s parsed title/content and re-runs the same
     keyword filter from step 1 against the fuller text.
   - **`GeminiArticleAnalyzer`**: one Gemini `generateContent` call per
     candidate does both jobs at once — reads the article's plain text and
     returns structured JSON (`is_ai_related`, `title`, `content`) using
     Gemini's `responseSchema`/`responseMimeType: application/json` mode, so
     the reply is guaranteed-parseable rather than free text to coax into
     shape. The `content` it returns is a short paraphrase, not verbatim
     scraped text — a secondary benefit beyond relevance judgment, since a
     paraphrased excerpt is more defensible to republish than a scraped
     block of the original site's text.

   `FallbackArticleAnalyzer` is what `NewsIngestionService` actually depends
   on: it calls Gemini only when configured (`GEMINI_API_KEY` set **and**
   `news_sources.gemini.enabled`), and falls back to `HeuristicArticleAnalyzer`
   on **any** failure — network error, rate limit (HTTP 429), quota
   exhaustion, malformed/non-JSON response. A Gemini outage degrades
   relevance-filtering precision and title/content quality back to the free
   heuristic path; it never breaks ingestion. Mirrors the same
   never-let-one-component's-failure-break-the-pipeline principle used
   throughout the media pipeline.

### Enabling Gemini

Set `GEMINI_API_KEY` in `.env` (leave empty to keep using the free heuristic
path — this is the default). `GEMINI_MODEL` defaults to
`gemini-2.5-flash-lite`; check
[ai.google.dev/gemini-api/docs/models](https://ai.google.dev/gemini-api/docs/models)
for the current cheapest option, since Gemini's model lineup and pricing
change often and this default is not guaranteed to still be current.

### Token/cost limits

Per the "per-call cap, no cross-request budget tracking" choice — every
Gemini call is bounded on both sides, but nothing tracks cumulative spend:

- **Input**: the article's plain text (`strip_tags`'d, whitespace-collapsed)
  is truncated to `news_sources.gemini.max_input_chars` (default 12000 ≈
  3000 tokens at ~4 chars/token) before being sent — a very long article
  costs the same as a short one.
- **Output**: `generationConfig.maxOutputTokens` is set to
  `news_sources.gemini.max_output_tokens` (default 500) on every request —
  more than enough for a boolean and two short strings.
- Every response's `usageMetadata` (prompt/output/total token counts) is
  logged via `Log::info('[gemini-analysis] token usage', ...)` for manual
  cost monitoring — there is no automatic budget ceiling or spend tracking;
  if that becomes necessary, the natural next step is a running counter
  (similar to `MediaPipelineProgressTracker`'s cache-based counter) checked
  in `FallbackArticleAnalyzer::geminiEnabled()` before ever calling Gemini.

## Deduplication

`news_items.canonical_url` has a unique DB constraint; `NewsIngestionService`
normalizes the candidate's URL with the same `UrlNormalizer` the media
pipeline uses for Level 1 image dedup (strip scheme noise, tracking params,
trailing slash) before checking for an existing row, and catches
`UniqueConstraintViolationException` as a race-safe fallback if two fetches
somehow overlap. Two sources reporting the same article (or an RSS feed and
a crawl of the same site both finding it) resolve to one `NewsItem`, not two.

## Failure isolation

Each fetched candidate becomes its own `ProcessNewsCandidateJob` — a single
unreachable, bot-blocked (403), or malformed article page fails and retries
independently (`$tries = 3`, `$backoff = [15, 60, 300]`), logged via
`Log::warning`, without affecting any other candidate from the same fetch or
blocking `FetchNewsSourceJob` from finishing its own retry/backoff
(`$tries = 3`, `$backoff = [30, 120, 600]`) for the source-level fetch
itself. This mirrors the media pipeline's "one bad asset shouldn't fail the
whole article" principle at the news-fetching layer.
