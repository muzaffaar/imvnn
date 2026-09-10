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
  something to work with too. Iterates every `<item>`/`<entry>` with a plain
  `foreach`, deliberately not `collect($items)->map(...)`: every sibling
  shares the same SimpleXML iterator key, and `Collection::make()` converts
  via `iterator_to_array($items)` — which defaults to preserving keys,
  silently collapsing all-but-one of several same-keyed siblings into a
  single array slot. Caught by testing against a real 10-item feed that this
  returned exactly 1 candidate; every configured RSS source was almost
  certainly only ever seeing its single most recent item per fetch until
  this was found and fixed. Capped to `news_sources.limits.max_items_per_feed_fetch`
  (default 20, newest-first per feed convention) — some real feeds carry a
  large historical backlog (confirmed: `huggingface.co/blog/feed.xml` has
  860 items, `research.google`'s has 100), and the cap keeps a new source's
  first sync from triggering hundreds of AI calls in one burst. Items
  already past the cap at first sync are never retroactively processed —
  intentional; the goal is ongoing new content, not backfilling a blog's
  full history.
- **`html_crawl`** (`HtmlCrawlSourceFetcher`) — for sites with no usable
  feed. Fetches one listing page (homepage or a section page) and looks for
  `<a>` links that *look like* individual articles, using only URL-shape
  heuristics — no title/body guessing from the listing markup, since that
  varies too much between sites; the actual article page is always fetched
  and parsed individually. Anchor text (when present) becomes the weak
  prefilter signal (see below).

  A link must clear a deny-list first — same host only (never follow
  off-site/syndication links) and not under an excluded path segment
  (`/tag/`, `/category/`, `/label/`, `/author/`, `/search/`, `/pricing/`,
  `/products/`, and more — see `EXCLUDED_PATH_SEGMENTS`, grown directly from
  the real junk links below) — and then clear a positive-evidence bar, not
  just "not obviously junk":

  1. a dated path with something *after* the year (`/2026/some-article`,
     `/2026/09/some-article`) — but a year as the *last* segment
     (`/blog/2026`) is a year-archive index, not an article, and is rejected
     even though it "has a date";
  2. starts with a known content-section word (`news`, `blog`, `press`,
     `features`, ...) and goes at least one level deeper (`/news/some-post`,
     `/blog/author/some-post`) — not the section index itself (`/blog`);
  3. starts with the same first path segment as the source page it was told
     to crawl, going deeper than that page (source-specific, for sites whose
     section word isn't in the fixed list above);
  4. a single root-level segment that's a long, heavily-hyphenated slug —
     some sites place featured posts at the bare root with no section prefix
     at all.

  A purely numeric final segment (`/blog/2026`, `/blog/page/2`) is rejected
  outright regardless of which rule would otherwise match — this is what
  rule 1's "year can't be last" carve-out and the general pagination-index
  exclusion both boil down to. This bar exists because the weaker original
  version ("any 2+-segment path, or a long hyphenated slug") is *not*
  conservative enough in practice: every real site's global nav menu links
  dozens of taxonomy/product/legal pages from every single page, and those
  links are just as likely to satisfy "multi-segment path" as an actual
  article — see "Real sites this was tuned against" below for the exact
  false positives that motivated each rule.

  Even with the stricter bar, remaining false positives (a "write for us"
  page with a heavily-hyphenated slug, say) are expected and fine — they
  cost one wasted fetch and then get rejected by the AI relevance filter or
  fail to parse a coherent article, same as the spec's stated tolerance.

### Real sites this was tuned against

Tested live against `anthropic.com/news`, `huggingface.co/blog`,
`mistral.ai/news`, `news.mit.edu` (homepage and topic-filtered), and
`research.google/blog`. Two real, non-obvious problems, now fixed:

- **A self-identifying bot User-Agent gets flat-out HTTP 403'd** by several
  of these sites (confirmed: anthropic.com, huggingface.co) even though
  there's no real anti-bot *challenge* behind it (no JS challenge, no
  CAPTCHA) — they simply filter on User-Agent string. A realistic browser
  UA + `Accept`/`Accept-Language` headers (`BoundedHttpFetcher::browserHeaders()`,
  `config('media.limits.user_agent')`) was sufficient for all of them; **no
  headless browser was needed** for any of the sites tested.
- **Nav menus drown out real articles** without the positive-evidence bar
  above — e.g. mistral.ai's page returned 20 product/pricing links and zero
  actual news posts under the old heuristic, because a same-host multi-segment
  path was treated as sufficient evidence on its own. research.google/blog
  additionally exposed a subtler case: its own year-archive links
  (`/blog/2020` .. `/blog/2026`) satisfied *both* the date-pattern rule and
  the known-section rule before the "numeric last segment" exclusion was
  added, since neither rule alone checked whether the year was actually
  followed by more path.

Where a real RSS feed existed under a non-obvious URL, it was used instead
of crawling (more reliable in general — see below): `huggingface.co/blog/feed.xml`,
`mistral.ai/rss.xml`, `research.google/blog/rss/`, `news.mit.edu/rss/feed`,
`news.mit.edu/rss/topic/{topic-slug}`, and MIT Technology Review's
`/topic/{topic}/feed/` pattern — none of these are linked from their
respective pages, only discoverable by trying the common WordPress/site
conventions. Anthropic had no discoverable feed and is crawled.

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
   avoids spending a fetch — or an AI call — on every discovered link.
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
   - **`AiArticleAnalyzer`**: one structured-output call per
     candidate does both jobs at once — reads the article's plain text and
     returns structured JSON (`is_ai_related`, `title`, `content`) using
     the configured provider's JSON mode, so the reply is machine-parseable
     rather than free text to coax into shape. The `content` it returns is a short paraphrase, not verbatim
     scraped text — a secondary benefit beyond relevance judgment, since a
     paraphrased excerpt is more defensible to republish than a scraped
     block of the original site's text.

   `FallbackArticleAnalyzer` is what `NewsIngestionService` actually depends
   on: it calls the AI provider only when configured (`AI_API_KEY` set **and**
   `news_sources.ai.enabled`), and falls back to `HeuristicArticleAnalyzer`
   on **any** failure — network error, rate limit (HTTP 429), quota
   exhaustion, malformed/non-JSON response. A provider outage degrades
   relevance-filtering precision and title/content quality back to the free
   heuristic path; it never breaks ingestion. Mirrors the same
   never-let-one-component's-failure-break-the-pipeline principle used
   throughout the media pipeline.

### Enabling an AI provider

Set `AI_PROVIDER=gemini` with `AI_API_KEY`, `AI_MODEL`, and `AI_BASE_URI` for
Google's native Gemini API. To use OpenAI or another compatible hosted or
self-hosted server, use `AI_PROVIDER=openai-compatible`, set its `AI_BASE_URI`
and `AI_MODEL`, then select its supported JSON mode with
`AI_OPENAI_STRUCTURED_OUTPUT` (`json_schema`, `json_object`, or `none`). A
trusted local OpenAI-compatible server may leave `AI_API_KEY` empty; Gemini and
hosted providers need their key. To keep the free heuristic path, set
`AI_ANALYSIS_ENABLED=false` or do not configure a provider endpoint.

### Token/cost limits

Per the "per-call cap, no cross-request budget tracking" choice — every AI
call is bounded on both sides, but nothing tracks cumulative spend:

- **Input**: the article's plain text (`strip_tags`'d, whitespace-collapsed)
  is truncated to `news_sources.ai.max_input_chars` (default 12000 ≈
  3000 tokens at ~4 chars/token) before being sent — a very long article
  costs the same as a short one.
- **Output**: the provider-specific output-token cap is set to
  `news_sources.ai.max_output_tokens` (default 500) on every request —
  more than enough for a boolean and two short strings.
- Every response's available prompt/output/total token counts are logged via
  `Log::info('[ai-analysis] token usage', ...)` for manual
  cost monitoring — there is no automatic budget ceiling or spend tracking;
  if that becomes necessary, the natural next step is a running counter
  (similar to `MediaPipelineProgressTracker`'s cache-based counter) checked
  in `FallbackArticleAnalyzer::aiEnabled()` before ever calling the provider.

## Freshness — only today's news

`FreshnessPolicy`, gated on `news_sources.freshness.only_today` (default on).
An article is publishable only on the calendar day it was published, in the
**audience's** timezone (`news_sources.freshness.timezone`, default
`Asia/Tashkent`) — not UTC and not the publisher's.

This exists because feeds carry far more history than their item count
suggests: the newest 20 items of a low-volume company blog can reach back a
quarter, which is how an article from May was posted in September.

Enforced at two points, which answer different questions:

1. **Ingestion** (`NewsIngestionService`) — don't create a `NewsItem` at all
   for an older article. RSS candidates carry a feed date, so most are
   dropped before spending an HTTP fetch *and* an AI call; crawled
   candidates have no date until the page is parsed, so they're re-checked
   after parsing but still before the AI call.
2. **Publishing** (`PublishNextReadyNewsItemJob::eligibleQuery`) — an
   article whose day has passed is abandoned rather than carried over, per
   *"if we did not manage to post it the same day, leave it unposted."*
   This also keeps the backlog count honest, since that count drives the
   posting cadence.

**An article with no determinable date is not fresh.** That's a deliberate
bias toward silence: an unknown date is far more often an old article than a
new one, and posting a stale item is the whole failure this policy exists to
prevent.

That decision put real weight on date extraction, and one source had *zero*
dated articles: anthropic.com publishes no date meta tag, no `<time>`
element and no JSON-LD — just plain text after the headline. Two things had
to be fixed in `ArticleContentExtractor` before it could be read:

- **`<script>` is stripped before any text scan.** Next.js embeds the whole
  page again as a serialized JSON hydration payload, in which the article's
  own title reappears around character 139,000 — the title-proximity search
  was landing there and scanning JSON instead of prose.
- **The date patterns carry no `\b` anchor before the month.** `textContent`
  concatenates adjacent elements without whitespace, so the date arrives
  glued to the headline (`...watermark worksAug 14, 2026Future Claude
  models`), and a word boundary can never match `worksAug`.

### A timezone trap worth knowing

`FreshnessPolicy::windowStart()` returns **UTC** deliberately. Eloquent binds
a `DateTimeInterface` to SQL by formatting it as-is, *without* converting the
timezone — so returning a `+05:00` boundary compares `2026-09-10 00:00`
against UTC-stored timestamps and silently discards everything published
between 19:00 and 24:00 UTC: the first five hours of every Tashkent day.
Carbon-to-Carbon comparison in `isFresh()` is unaffected (it compares
instants), which is exactly what makes the SQL side easy to get wrong.

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
