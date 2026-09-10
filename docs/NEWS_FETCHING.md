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

## AI relevance filtering (`AiRelevanceFilter`)

Applied **twice** per candidate, per the medium-effort tradeoff chosen for
this project (keyword filter, not an LLM classifier — see below for when to
reconsider that):

1. **Prefilter**, before any HTTP fetch of the article page: title + summary
   for RSS candidates, or just the anchor text for `html_crawl` candidates
   (the only signal available before visiting the page). Matters most for
   crawled sources, where it avoids spending a fetch on every discovered
   link.
2. **Authoritative filter**, after parsing: the real title + parsed content.
   This is what actually gates `NewsItem` creation.

Matching is case-insensitive, word-boundary (`\b...\b`) regex against
`config('news_sources.ai_keywords')` — a short phrase needs only one match
anywhere in the text. Word boundaries matter for short keywords like `ai`:
`\bai\b` matches "new **AI** tool" but not "s**ai**d" or "m**ai**ntain",
since a word boundary requires an actual transition between a word and a
non-word character.

**When to move beyond a keyword filter:** if you start seeing systematic
false positives (e.g. "AI" as a person's initials, a company ticker) or
false negatives (an article that's clearly AI-related but never uses any of
the listed terms — jargon drift, a new model/company name not yet in the
list), the next step is the same tiered pattern used for media relevance in
`RelevanceAnalysisService`: keep the keyword filter as a free first pass to
avoid paying for every candidate, then send only the survivors (or, for the
prefilter stage, only candidates the keyword filter is *unsure* about) to an
LLM classifier for a real judgment call.

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
