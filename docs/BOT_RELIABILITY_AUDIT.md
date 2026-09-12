# Telegram bot reliability audit

Reviewed ingestion, article parsing, media extraction/ranking, caption composition, scheduling and Telegram publishing against the supplied 40 failure modes.

## Fixed

- Duplicate queue deliveries/concurrent publishers: an atomic, durable `telegram_publish_started_at` claim prevents another send for the same news item. This follows the existing global, per-news-item publishing model.
- Retry duplicates: transport failures, server errors and missing/partial delivery receipts are treated as uncertain outcomes. They cannot enter the media fallback ladder or automatically resend.
- Rate limits: decode Telegram error responses even on non-2xx HTTP status and release the job for `retry_after`. Only explicit media-related 400 rejections permit media fallback. Authentication and permission errors do not trigger alternate sends.
- Token exposure: Telegram transport exception URLs are no longer retained in failed-job exceptions or logs.
- Caption formatting: truncate decoded text, then escape it again; count UTF-16 units conservatively; bound pathological oversized headers.
- Media quality and ordering: probe dimensions before choosing among duplicate renditions, recompute scores after probing and enforce the minimum score again.
- Contextual relevance: event-pool entries no longer overwrite an article's own media pivot scores.
- Video fallback: never send a video URL as a photo; thumbnail-only plans no longer tell the caption model that a video is attached.
- Scraping: omit navigation/sidebar/footer content and media, prefer lazy-loaded originals/srcsets, and match embed hosts at domain boundaries.
- Broken media URLs: use RFC URI resolution instead of filesystem `dirname`, which generated backslashes on Windows and mishandled relative paths.
- Language completeness: reject missing configured language sections and malformed non-string model fields.
- Link previews: disable automatic previews for text posts.
- Freshness boundary: scheduler excludes the following day's exact midnight, matching ingestion.

## Articles resent after the articles table was rebuilt

`news_items.telegram_published_at` was the only record of a delivery, and it
answers a narrower question than it appears to: not "has this article been
sent" but "has *this row* been sent". It dies with the row. Re-syncing sources,
clearing stale articles, or re-ingesting after a reset all produce fresh rows
with an empty publish history, and the scheduler posts them again. Three
articles already in the channel went out a second time that way.

`published_posts` is now the durable answer, keyed on `(telegram_channel_id,
canonical_url)` with a unique index on a digest of the URL. The canonical URL is
the right key because it is what identifies an article across ingestions — the
same value `news_items.canonical_url` is already uniquely indexed on.

Checked in two places, for two different reasons:

- `PublishToTelegramJob`, immediately before sending. This is the strict guard.
  It also stamps the new row's `telegram_published_at` so the article stops
  being reconsidered.
- `PublishNextReadyNewsItemJob::eligibleQuery`, so the backlog count that drives
  the posting cadence does not include articles that can never be sent.

The row is written straight after a confirmed delivery and before any of the
remaining bookkeeping, because losing it means the article can go out again. The
write is idempotent: a duplicate key is swallowed rather than thrown, since
throwing there would push the job into a retry that could publish twice — the
exact failure the table exists to prevent.

The creating migration backfills from `news_items.telegram_published_at` so an
existing installation does not start with an empty ledger and repost its day.
Publishing state in `news_items` is global rather than per channel, so the
backfill attributes each sent article to every channel: exact for one channel,
and deliberately conservative for several, since suppressing a post that a
second channel never received beats sending a duplicate.

**This does not survive `migrate:fresh`**, which drops the ledger along with
everything else. Nothing stored in the database can. Treat that command as
"repost today's news" and prefer clearing `news_items` alone.

## Today's news dropped because the timezone offset was discarded

The mirror image of the stale-post bug, found while verifying it.

Eloquent binds a `DateTimeInterface` to SQL by formatting it as-is, *without*
converting the timezone. `FreshnessPolicy` already accounts for that when it
builds its window boundaries; the parsed article date did not. So any date
carrying an offset was stored as its local wall-clock reading.

The AWS Machine Learning blog publishes with `-08:00`. An article stamped
`2026-09-10T13:58:09-08:00` is really 21:58 UTC, which is early on the 11th in
Tashkent and therefore today's news. It was saved as `13:58:09` and read back
eight hours early, landing outside the window. Three AWS articles were ingested
and then sat permanently unpublishable.

The error runs both ways: a `+05:00` timestamp stored as-is reads five hours
late, which can carry yesterday's article into today. `PublishDateParser` now
returns the storage timezone, so both halves of the comparison are in the same
zone.

## Today's news dropped again, because the storage timezone was configured twice

The same failure one layer up. `FreshnessPolicy` read its own config key for
the zone timestamps are stored in, and that key said `UTC` while the columns
held `Asia/Tashkent` readings — `news_items.published_at` of `2026-09-11
18:45:00` for an article the MIT feed stamped `09:45:00 -0400`, five hours
later than UTC.

Nothing failed. The day window simply slid five hours: the scheduler's SQL
selected from 19:00 yesterday to 19:00 today, queued articles from yesterday
evening, and `PublishToTelegramJob`'s send-time re-check — comparing instants,
and therefore right — rejected each one as `not_fresh_at_send_time`. Today's
news published before 19:00 was never selected at all.

Two things fix it. The storage timezone is now a single key,
`app.storage_timezone`, read through `App\Support\Time\StorageTimezone` by
the parser, the freshness window and hydration alike, so no two layers can
hold different answers. And hydration no longer infers it: `published_at` and
every other stored timestamp casts through `App\Casts\StoredDateTime`, which
reads the column in the storage zone rather than in `app.timezone` and
converts on the way back. `app.timezone` now only decides how a timestamp is
displayed.

## Images from the related-posts strip below the article

Posts were carrying pictures of *other* articles, taken from the "read next"
strip under the story.

The root cause turned out to be one line, and its blast radius was wider than
the symptom. `ExtractMediaJob` passed `canonical_url` as the extraction base
URL. That column is a deduplication key produced by `UrlNormalizer`, which
strips the scheme, so it is not a resolvable URL at all. Consequences:

- Every relative `<img src>` on every page failed to resolve and was silently
  discarded — part of the original "it is not fetching all images" complaint.
- The "is this image linked to another article" rule could never resolve an
  href, so it passed everything on any source that links with a relative path.
  That is how three other articles' hero images reached a research.google post,
  and how a page full of Hugging Face contributor avatars survived extraction.

Position filtering was added on top: everything below the article body is
removed, the body being located by paragraph density rather than by taking the
first `<article>` (related cards are `<article>` elements too — 34 on one page).

Nothing *above* the body is removed, and that was measured rather than assumed.
Scoping extraction into the body container, the obvious reading of the bug
report, is wrong: across the configured sources the hero and sometimes the
article's own charts fall outside the densest container. On blog.google it left
2 of 4 real images; on the NVIDIA blog it dropped the hero and both charts.

Effect across the 56 stored articles: 4.3 publishable images per article, above
the 4-slot album cap, with no article left with nothing to post. Covered by
`tests/Feature/ImageFilteringTest.php`, which pins the hero-above-body and
no-locatable-body cases alongside the related-strip ones.

## A year-old article published as today's news

A Stability AI post from **10 September 2025** was published to the channel on
**11 September 2026**. Root cause, in two parts:

**Carbon fills a missing year in with the current one.** `CarbonImmutable::parse('Sep 10')`
returns 10 September of *this* year. Stability AI's pages carry a Squarespace
`<time class="dt-published" datetime="Sep 10">` with no year at all, so the
article's date was manufactured as 2026-09-10 — yesterday. This is the one
failure mode a freshness filter is powerless against: the date it is handed
already looks fresh, so every downstream check passes.

`tryParseDate()` now refuses any value that does not state a 4-digit year
outright, which also covers relative phrasings ("2 days ago", "yesterday") that
are only meaningful against a render time we do not have. An undated article is
not fresh and gets dropped, which is the intended outcome: a missing date costs
one article, an invented one costs the channel's credibility.

**The wrong source was consulted first.** The same page carries the true date
twice — `<meta itemprop="datePublished" content="2025-09-10T14:07:07+0000">` and
the identical value in JSON-LD — but `<time>` was read before JSON-LD, and the
meta scan matched only `property` and `name`, never `itemprop`, so schema.org
microdata was invisible. Order is now by authority: year-bearing meta tags
(including `itemprop`), then JSON-LD, then `<time>`, then visible text.

**A second gate was added at send time.** `PublishToTelegramJob` re-checks
freshness immediately before calling Telegram. The scheduler's check does not
expire, and a retry backoff, a rate-limit release, or a backlog draining past
midnight can put hours between the two. One stale post does more damage than one
missed post.

Auditing every stored article against a re-parse found exactly one corrupted
record — the one that was posted. Its `published_at` has been corrected to
2025-09-10, so it cannot be selected again. The Telegram message itself is still
in the channel.

Covered by `tests/Unit/ArticleDateExtractionTest.php` and the freshness cases in
`tests/Feature/PublishingSafetyTest.php`.

## Wrong and duplicated images in published posts

Two defects were visible in published albums.

**The same picture in two slots.** `lh3.googleusercontent.com` encodes the
rendition as `=w2000-h1260-n-nu` glued onto the image id, with no dot and no
extension. Every `RenditionKeyBuilder` pattern anchors on a `.` or a trailing
`-NNNxNNN`, so none matched: one DeepMind figure served at five sizes produced
five keys, and two of them took two of four slots in one album. The key now
truncates at the first `=`.

**Author and contributor avatars published as article images.** Quality scoring
cannot catch these, and that is the point worth recording: an avatar is not a
low-quality image. It is sharp, well-compressed and ideally proportioned for
Telegram, so every quality signal rates it highly. On one Hugging Face article,
ten of sixteen candidates were contributor avatars scoring 0.57-0.69 against a
0.35 floor while the article's own figures scored 0.61 — the avatars ranked
*above* the real content. A 200x200 avatar also passed the 200px minimum, which
is a strict `<` comparison.

`ImageRoleClassifier` now answers "what is this image" separately from "how good
is it", returning `content`, `avatar`, `chrome`, `pixel` or `promo` from URL, alt
text and dimensions. Only `content` is publishable. It runs at extraction, so no
row is created, and again after probing, where the small-square rule catches
avatar CDNs that no pattern covers yet. See docs/MEDIA_ARCHITECTURE.md.

**Fewer images than the article has.** Deduplication ran *after* the probe
window was taken, so five renditions of one figure and a run of avatars could
consume the entire budget and the article's remaining figures were never probed
or published. The window is now budgeted in distinct pictures, with every
rendition of a chosen picture still probed, because which rendition to keep can
only be decided from real dimensions. Lazy-loading attributes beyond
`data-src`/`data-srcset` are also read now, and `src` is consulted last, since a
lazy image's `src` is usually a placeholder rather than the photograph.

Over-filtering proved more costly than under-filtering. Adding `related` and
`sidebar` to the stripped-container list removed an article's own figures,
because a class like `sidebar-right` describes the page's layout rather than the
element's role. The structural strip list is therefore narrow and limited to
words that can only name a person or a discussion; the classifier does the real
work. Covered by `tests/Unit/ImageRoleClassifierTest.php` and
`tests/Feature/ImageFilteringTest.php`, which pin the false-positive cases
alongside the true ones.

## Stranded publication candidates

A second pass found three ways an article could become permanently
unpublishable while every visible health signal stayed clean — no failed job,
no error row, just a channel posting less than it should.

`PublishNextReadyNewsItemJob` selects only from items whose
`media_analysis_completed_at` is set, so any path that skips that timestamp
removes the article from consideration forever. All three ran in `failed()`
handlers or cache-expiry paths, i.e. after the last retry, leaving nothing to
retry.

- `ExtractMediaJob::failed()` logged and stopped. Extraction produces media,
  not the article, so it now dispatches `AnalyzeMediaForNewsItemJob` anyway
  and the article publishes as text — which is what `PostMediaType::None`
  already exists for.
- `AnalyzeMediaForNewsItemJob::failed()` logged and stopped. It now sets
  `media_analysis_completed_at` if it is still null, leaving an
  already-published article untouched. Media scoring is an enhancement, not a
  precondition for publishing.
- `MediaPipelineProgressTracker::complete()` returned false when its cache
  counter was missing, so a two-hour expiry, a cache flush or a restarted
  Redis during an in-flight fan-out meant analysis was never dispatched. It
  now treats a lost counter as the final job. The cost is a possible repeated
  analysis pass, which is idempotent; the alternative was an invisible
  article.

Covered by `tests/Feature/PublishCandidateRecoveryTest.php`.

Separately, an AI caption failure aborts the publish unless
`TELEGRAM_CAPTION_FALLBACK_ORIGINAL` is on. That is deliberate for a
language-specific channel and is listed here only because "the bot must always
post" and "the bot must never post untranslated" cannot both hold — the
setting is where that trade is chosen.

## Deployment and uncertain delivery recovery

Run `php artisan migrate` before restarting workers with this code. The migration adds a nullable timestamp and was exercised with the SQLite test database; the live database was not migrated and no Telegram messages were sent.

Items with `telegram_publish_started_at IS NOT NULL` and `telegram_published_at IS NULL` may be in flight or have an uncertain outcome. Check failed jobs/logs and the channel before acting. If a post exists, reconcile its published state. Only after confirming no delivery and no active worker should an operator clear the start timestamp and retry the publishing job. Never bulk-clear these claims: a worker can crash after Telegram accepts a post but before the database records success. This policy favors avoiding duplicates at the cost of holding uncertain posts for review.

## Remaining gaps

- Cross-source semantic story deduplication and minor-update clustering are absent from ingestion; canonical URL uniqueness only catches URL-equivalent articles. Event relationships exist, but ingestion does not assign stories to events.
- Publishing state in `news_items` is still global to the article rather than per channel, so a second channel cannot independently deliver a story the first has already sent. The `published_posts` ledger is keyed per channel and is the foundation for fixing this, but the `telegram_published_at` flag the scheduler reads is not.
- Scheduler tokens are not consumed per execution; duplicate executions with the same token can still fork a scheduler chain. Individual item claims prevent duplicate articles, but channel cadence is not guaranteed at actual send time.
- AI prompts request factual summaries, but no independent factual verifier checks names, numbers, quotations or translation accuracy. Script validation alone cannot establish language correctness. Humor and tone remain configurable existing policy.
- Article date fallback can still mistake a visible event/update date for publication time; canonical links and redirects are not reconciled with publisher-declared article identity. Yearless and relative dates are now refused outright rather than resolved against the current year, so the remaining risk is a *wrong* stated date rather than an invented one.
- Media selection is heuristic and probing is bounded to twelve distinct pictures per article. Image *role* (content vs avatar/chrome/pixel/promo) is now classified explicitly, but semantic relevance, watermark detection, near-duplicate reference images and best-HD-video discovery are still not guaranteed. The role classifier is pattern- and geometry-based: an avatar that is large, non-square and served from an article CDN would still pass, and only a vision model would catch it.
- Source names are already included by the caption header; article attribution links and Telegram buttons remain absent by existing design.

## Validation

`php artisan test` covers queue redelivery/claims, uncertain outcomes, rate-limit delays, Telegram response classification, caption entities/length, lazy image extraction, boilerplate exclusion and rendition quality selection. HTTP and publishing are mocked; tests send no real posts.

Protocol reference: [Telegram Bot API](https://core.telegram.org/bots/api), including `ResponseParameters.retry_after` and message/media response shapes.
