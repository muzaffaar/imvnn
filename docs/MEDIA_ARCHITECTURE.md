# Media Pipeline Architecture

This document explains the *why* behind the implementation in `app/Services/Media`,
`app/Services/Telegram`, `app/Jobs/Media`, `app/Jobs/Telegram`, and the
`media_*` migrations. Code comments cover local detail; this file covers
cross-cutting decisions that don't belong in any one file.

## Scope of this codebase

This repo implements the **media pipeline**, plus enough of a **news-fetching
pipeline** (`App\Services\News`, `App\Jobs\News` — see
[`docs/NEWS_FETCHING.md`](NEWS_FETCHING.md)) to actually populate `NewsItem`
rows from real RSS feeds and crawled pages, feeding directly into
`ExtractMediaJob` below. Event *clustering* (deciding which articles describe
the same real-world happening) and news *scoring/prioritization* (deciding
which candidate articles are worth publishing at all) remain out of scope —
`NewsItem::event_id` and `TelegramChannel` exist just deep enough for the
media pipeline to be exercised end-to-end.

## Pipeline overview

```
NewsItem saved (by the news pipeline, elsewhere)
        │  dispatches, does not call synchronously
        ▼
ExtractMediaJob            (queue: media-extraction)
        │  MediaExtractionManager runs every MediaExtractorInterface,
        │  then PlatformAdapterInterface (YouTube, ...) normalizes results,
        │  then MediaIngestionPolicy decides ignore/reference/download per candidate,
        │  then MediaDuplicateDetectionService Level 1 (URL) dedups against existing assets
        ▼
   ┌────────────────────┬─────────────────────────┐
   ▼                    ▼                         ▼
DownloadMediaJob   ProcessVideoJob          (reference-only image/embed:
(media-download)   (video-processing,       marked Ready immediately,
 images/documents   isolated pool)           nothing to fetch)
 Level 2 (hash) +
 Level 3 (pHash) dedup
   └────────────────────┴─────────────────────────┘
                         │  MediaPipelineProgressTracker (see below) fires this
                         │  once every fanned-out job for the article is done
                         ▼
AnalyzeMediaForNewsItemJob (queue: image-analysis)
        │  RelevanceAnalysisService (cheap score already set at extraction;
        │  VisionRelevanceAnalyzerInterface only for the top few candidates)
        │  MediaQualityScorer caches quality_score per usable asset
        │  EventMediaPoolService contributes usable assets into event_media
        ▼
(separately, once the news pipeline decides to publish an article)
SelectMediaForPublishingJob (queue: media-selection)
        │  MediaSelectionService picks from the article's media ∪ its event's pool
        │  MediaVariantService generates the 'telegram' variant lazily, only now
        ▼
PublishToTelegramJob (queue: telegram-publishing)
        │  TelegramPublisher, with a fallback ladder (see below)
        ▼
   Telegram channel
```

Every arrow above is a queue dispatch, never a synchronous call — see
"Never block news ingestion" below.

## Duplicate detection

Implemented in `app/Services/Media/Deduplication`. Four levels, cheapest
first, short-circuiting on the first hit:

1. **URL match** (`MediaDuplicateDetectionService::findByUrl`) — normalizes
   the URL (`UrlNormalizer`: lowercase host, strip tracking params like
   `utm_*`/`fbclid`, strip trailing slash/fragment) and looks up
   `media_assets.canonical_url`. Runs at extraction time, before any
   download — the cheapest possible check.
2. **Content hash** (`findByContentHash`) — SHA-256 of the downloaded bytes,
   enforced as a unique DB constraint on `media_assets.content_hash` so this
   is effectively "insert and let the index tell you." Catches the same file
   served from two different URLs (a wire photo mirrored on two CDNs).
3. **Perceptual hash** (`findByPerceptualHash`, `PerceptualHasher`) — a 64-bit
   dHash (resize to 9x8 greyscale, compare adjacent pixels), compared by
   Hamming distance against recent images (`perceptual_hash_lookback_days`,
   default 30) below a configurable threshold (`perceptual_hash_hamming_threshold`,
   default 8/64 bits). Catches resized/recompressed/lightly-cropped copies —
   the "same photo, different outlet's CDN settings" case. Implemented in
   pure PHP (no `ext-gmp`) since 64 bits comfortably fits as a bit string.
4. **Semantic embeddings** (`EmbeddingSimilarityDetectorInterface`) — **not
   wired into the default pipeline.** dHash is blind to rotation, heavy
   cropping, and watermarking; an embedding model (e.g. CLIP) catches those,
   but needs a model/service call and a vector index per comparison.

   **When to actually enable it:** only for a narrow, high-value slice of
   traffic — e.g. candidates for a *featured* image on a *high-priority*
   event, where picking a near-duplicate of already-published art is worth
   the extra cost, or where dHash's blind spots (rotated/cropped/watermarked
   copies) are common for your sources (e.g. aggregators that re-crop wire
   photos). Do not run it on every image; bind a real implementation and
   flip `media.deduplication.embedding_similarity_enabled` only once you've
   measured that Level 3 is missing duplicates that matter.

### Level 0 — CDN renditions, at selection time

The four levels above all fail for the most common real-world duplicate: one
image served under several URLs by a CDN. research.google ships every
article figure as both `original_images/X.png` and
`images/X.width-1250.png`; blog.google as `X.width-1300.png` and
`X.width-200.format-webp.webp`. The URLs genuinely differ (Level 1 misses
them), and reference-only assets are never fetched, so no content or
perceptual hash ever exists (Levels 2-3 never run). Left alone, the same
picture is attached to a post two or three times.

`RenditionKeyBuilder` produces a "same picture" key from host + filename
with CDN rendition markers stripped (`.width-N`, `.format-X`, `-600x600`,
`@2x`, trailing content hashes, ...), deliberately ignoring the directory
since that's exactly what differs between renditions. A filename shorter
than 8 characters falls back to the full path, so two unrelated `hero.jpg`
under different article paths don't collapse into one.

Applied in `MediaSelectionService` *after* ranking — so the surviving
rendition of each picture is its best-scoring one — rather than at
ingestion, keeping it non-destructive and consistent with the rule below.

Duplicates are never deleted: the loser row gets `status=duplicate` and
`duplicate_of_id` pointing at the canonical row (`MediaAsset::canonical()`).
Everything downstream (selection, scoring) reads through `canonical()`, so a
pivot that happens to point at a since-resolved duplicate still works.

## Quality formula

`MediaQualityScorer`, weights in `config('media.quality_weights')`:

```
quality_score =
    0.25 × relevance
  + 0.15 × resolution
  + 0.15 × visual_quality       (sharpness proxy, watermark/corruption penalty, file-size fitness)
  + 0.15 × source_reliability
  + 0.10 × telegram_compatibility
  + 0.10 × uniqueness           (penalizes assets many other extracted copies resolved as duplicates of)
  + 0.10 × aspect_ratio         (added term — see below)
```

This is the spec's formula with one change: **aspect ratio was split out of
resolution into its own term**, and `resolution`/`source_reliability` each
gave up 5 points to make room. Rationale: a high-resolution, on-topic,
reliable-source image cropped to a tall portrait or ultra-wide banner still
makes a bad Telegram post image — resolution alone doesn't capture that, and
folding it into `visual_quality` would have buried a very concrete, cheaply
computed signal (`width/height` vs. `config('media.ideal_aspect_ratio')`,
1.91 ≈ 1200×630) inside a fuzzier one.

`visual_quality` itself is a heuristic, not a real no-reference IQA model:
sharpness is an average-neighbor-pixel-gradient proxy (`SharpnessEstimator`)
computed once at download time and cached in `media_assets.metadata`. That's
intentional — it runs on every downloaded image, so it has to be cheap. It
will not catch subtle JPEG artifacting or AI-generated-image tells; if that
matters for your use case, swap in a real IQA model behind the same cached
`sharpness_score` metadata key.

## AI relevance pipeline

Two-stage, per `RelevanceAnalysisService` / `MediaRelevanceScorer`:

1. **Cheap, rule-based** (`MediaRelevanceScorer`, runs on every candidate at
   extraction time, no I/O): keyword overlap between the article title and
   the candidate's caption/alt text, position in the source document, and
   how much the extractor itself is trusted (`og:image`/RSS enclosure/JSON-LD
   ≈ "the publisher chose this," vs. a generic `<img>` scan).
2. **Vision/multimodal model** (`VisionRelevanceAnalyzerInterface`) — only
   called for the top `media.relevance.max_candidates_for_ai_analysis`
   (default 3) candidates that *also* clear `min_score_for_ai_analysis`
   (default 0.35). An image that's already obviously wrong (ad-banner
   aspect ratio, near-zero keyword overlap, tiny dimensions) never reaches
   the model at all. The result is blended 40/60 with the cheap score
   (`RelevanceAnalysisService`), not used to fully override it — a single
   model call shouldn't overrule multiple independent cheap signals outright.

Default binding (`NullVisionRelevanceAnalyzer`) is a no-op that returns the
cheap score unchanged, so the pipeline runs end-to-end with zero model cost
until a real implementation is bound in `MediaServiceProvider`.

**When to use plain computer vision vs. embeddings vs. a full multimodal
model**, concretely:

- **Computer vision (classic, e.g. face/logo/text detection)** — cheap,
  deterministic checks: watermark detection, NSFW/violence filtering, logo
  detection to downrank generic branding shots. Run on every downloaded
  image if you need these signals; it's cheap enough.
- **Embeddings** — similarity search (Level 4 dedup above, or "find images
  like this one already in our library"). Run only on the narrow slice
  described above.
- **Full multimodal/vision-LLM call** — genuine semantic judgment ("does
  this image actually depict what the headline describes"). Most expensive
  and highest-latency; reserved for the top-N shortlist as described above,
  never the full candidate set.

## Video download decision

`VideoDecisionService` — deliberately the most conservative part of the
pipeline, since downloading video is simultaneously the most expensive
(storage/bandwidth) and the most legally exposed (copyright, platform ToS)
action this system can take. It never defaults to "download"; every
download requires an affirmative reason:

| Condition | Decision |
|---|---|
| `external_provider` is YouTube/Twitter/Instagram/TikTok/Facebook/etc. (`media.video.reference_only_providers`) | Reference only. Fetch the platform's own thumbnail (e.g. `i.ytimg.com`) if available — cheap, no ToS concern. Never re-host the video itself. |
| Source has not granted `media_reuse_permitted` | Reference only, regardless of size. |
| Probed size unknown (HEAD failed/unsupported) | Reference only — never commit to a download blind. |
| Probed size exceeds `media.limits.max_video_download_bytes` (default 200MB) | Reference only. |
| First-party/reuse-permitted source, known size within budget | Download. Probe with `ffprobe`, generate a thumbnail, and transcode to a Telegram-compatible MP4 (`FfmpegService::transcodeForTelegram`) only if `ffprobe` shows the source isn't already compatible. |

This runs in `ProcessVideoJob` on the isolated `video-processing` queue —
see Queues below — never inline during extraction, since it needs a network
round-trip (HEAD request) that extraction shouldn't wait on.

## Storage strategy

PostgreSQL holds only metadata and relationships (`media_assets`,
`media_variants`, `media_metadata`, `media_processing_logs`); actual bytes go
through `MediaStorageService` to whatever disk `config('media.disk')` points
at (S3, Cloudflare R2, MinIO, or local disk for dev — all via Laravel's
`s3`-compatible Flysystem driver, see `config/filesystems.php`).

Object keys (`MediaStorageKeyBuilder`) are **content-hash-addressed**, not
per-article:

```
media/{yyyy}/{mm}/original/{sha256}.{ext}
media/{yyyy}/{mm}/processed/{variant}/{sha256}.{ext}
media/{yyyy}/{mm}/thumbnails/{sha256}.{ext}
```

This is a deliberate deviation from a naive `news/{news_item_id}/...`
layout: a `media_assets` row is a single physical asset that can be attached
to many articles (`news_media`) and events (`event_media`). Keying storage
by article would either duplicate the same bytes under every article that
reused them, or require choosing one "owning" article arbitrarily. Keying by
`content_hash` means `MediaStorageService::putOriginal()` can check
`existsByPath()` first and skip the upload entirely when the exact same
bytes were already stored for a different article — the "avoid duplicate
storage" requirement, satisfied structurally rather than by a separate
cleanup pass.

## Media variants — generated lazily

`media_variants` rows (and the object storage bytes behind them) are created
**on demand**, not eagerly for every downloaded asset:

- `DownloadMediaJob`/`ProcessVideoJob` store only the `original`.
- `SelectMediaForPublishingJob` calls `MediaVariantService::ensure()` for the
  `telegram` variant only for assets actually chosen for a post.
- `GenerateMediaVariantJob` exists for out-of-band cases (admin backfill,
  manual retry) but isn't part of the default flow.

This is the "lazy processing" principle from the spec: most extracted
images are never selected for publishing at all (an article might have 15
inline images and only 1 gets posted), so eagerly generating 4 variants ×
15 images would be almost entirely wasted CPU/storage.

## Telegram media selection & post-type decision

`MediaSelectionService::selectForNewsItem`:

1. Gather the pool: the article's own media **∪** its event's shared pool
   (`event_media`) if it belongs to one (see below).
2. Resolve every asset to its canonical form (`MediaAsset::canonical()`),
   filter to `isUsable()` (not failed/rejected/still a duplicate row), and
   drop anything below the channel's `min_quality_score` rule.
3. Rank by cached `quality_score` descending.
4. Decide the shape:
   - A qualifying video, and the channel prefers video
     (`rules.prefer_video`, default true) → **Video** if we actually hold
     the bytes and it's Telegram-compatible, else **VideoThumbnailFallback**
     (post the thumbnail/best image, link the original video in the caption).
   - Otherwise, images: 1 qualifying image → **SingleImage**; 2+ and the
     channel allows it (`rules.allow_media_group`, capped by
     `rules.max_images` and Telegram's own 10-item limit) → **MediaGroup**;
     otherwise falls back to **SingleImage**.
   - Nothing qualifies → **None** (text-only post).

Channel-specific behavior lives in `telegram_channels.rules` (JSONB) rather
than code, so per-channel tuning doesn't need a deploy.

## Event media pool

`EventMediaPoolService::syncFromNewsItem`, called from
`AnalyzeMediaForNewsItemJob` once an article's media finishes scoring:
every *usable* asset on the article gets upserted into `event_media` for its
parent event, carrying the article-specific relevance score. This is what
lets `MediaSelectionService` pick, say, an official press-kit photo attached
to one article to illustrate a Telegram post actually generated from a
different (text-only) article about the same event.

## Publishing scheduler (`PublishNextReadyNewsItemJob`)

Nothing in the pipeline auto-selects an article for publishing — that would
mean the moment several good articles finish media analysis around the same
time, they'd all get posted in a burst. Instead, one article at a time, per
this requirement: *post every 2 hours normally; if more good news is ready,
post faster — randomly, between 15 minutes and 2 hours — scaled by how much
is waiting*, not all at once regardless of backlog size.

`PublishNextReadyNewsItemJob` is a **self-perpetuating** job: each run picks
at most one candidate, dispatches it, and — in a `finally` block, so this
happens whether or not a candidate was found or the dispatch succeeded —
reschedules itself with a computed delay (see Cadence below). Laravel's own
job retry is deliberately disabled (`$tries = 1`) so a failed attempt can't
spawn a second parallel chain; the `finally` reschedule is the only thing
keeping it alive, by design.

Started with `php artisan publishing:start {channel}`, which is idempotent:
it mints a fresh `telegram_channels.publish_chain_token`, and any chain
still running under an older token retires on its next run instead of
posting alongside the new one.

That singleton guard is not optional bookkeeping — chains fork on their own.
Force-killing a worker mid-job leaves the job *reserved*; Laravel re-runs it
once `retry_after` elapses, and the interrupted run's `finally` then
reschedules a second chain alongside the successor it had already
dispatched. Three concurrent chains were observed after two worker restarts
during development, which tripled the posting rate and burst nine posts into
a live channel in half an hour. Each chain still claims any given article at
most once (claiming is atomic, see below), so forks never *duplicate* a
post — they only break the pacing, which is the whole point of the
scheduler.

**Eligibility** — a `NewsItem` is a candidate only once
`media_analysis_completed_at` is set (by `AnalyzeMediaForNewsItemJob`, once
the whole media pipeline for it has finished) and neither `publish_queued_at`
nor `telegram_published_at` is set yet.

**Ranking** — among eligible candidates, the one with the highest cached
`quality_score` among its *ready* media wins (`withMax` on the `mediaAssets`
relation, Postgres `NULLS LAST` so text-only-eligible articles with no
scored media rank behind anything with media, not ahead of it), tie-broken
by whichever finished analysis first.

**Claiming** — `publish_queued_at` is set via a single conditional
`UPDATE ... WHERE publish_queued_at IS NULL`, checking the affected row
count. This is what makes concurrent scheduler runs (two chains, or a
retry) safe: only one caller's `UPDATE` actually matches and returns a
non-zero count, so only one of them proceeds to dispatch
`SelectMediaForPublishingJob`. `telegram_published_at` is set later, by
`PublishToTelegramJob`, once a post actually succeeds — an item that gets
claimed but never successfully posts (e.g. `sendTextOnly` itself failing)
stays claimed forever rather than being retried automatically, consistent
with treating a total posting failure as something a human should look at,
not paper over (see the fallback ladder below).

**Cadence** is per-channel, read from `TelegramChannel.rules` (same JSON
blob that already holds `prefer_video`, `max_images`, etc., so tuning needs
no deploy) via `computeDelaySeconds()`:

- **No other candidate waiting** (`backlogCount == 0`): the delay is exactly
  `max_publish_interval_minutes` (default 120 = 2h) — a steady drip when
  supply is scarce, not randomized, since there's nothing to rush for.
- **Backlog present**: the delay is random between `min_publish_interval_minutes`
  (default 15) and a ceiling that shrinks from the 2h baseline down toward
  15 minutes as the backlog grows, reaching the 15-minute floor once the
  backlog hits `publish_backlog_saturation_count` (default 5) — i.e. the
  more good news is queued up, the faster (and still randomly-timed) it
  gets worked through. Backlog size is recomputed on every reschedule
  (the same eligibility query `pickBestCandidate()` uses, minus the ranking),
  so the pacing continuously adapts as new articles finish analysis or get
  claimed.

## Post format and length budget

A post is assembled from a bold source line, the article's publish time in
the channel's timezone (`media.telegram_caption.display_timezone`, default
`Asia/Tashkent`), a bold headline plus 2-3 sentence summary per language,
and 2-4 topical hashtags — no links of any kind, since the post is meant to
stand on its own rather than tease a click. `PostHeader` renders the shared
source/time/hashtag pieces so the AI and plain composers can't drift
apart in appearance.

Languages come from `media.telegram_caption.languages` (currently Uzbek,
Latin script) rather than being hardcoded: that list drives the AI-provider
response schema, the prompt, and the assembled sections together. The Uzbek
prompt explicitly prohibits Russian, English, and Cyrillic apart from exact
proper names, brands, product names, acronyms, numbers, and dates. Each extra
language would compete for the same 1024-character caption budget.

When the AI provider can't produce a caption, the only fallback is the article's own
words — i.e. the source's language, usually English. For a channel that
publishes in specific languages that's worse than staying quiet, so
`fallback_to_original_language` is **off** by default: the publish attempt
fails and retries instead of going out untranslated.

`CaptionBudget` enforces Telegram's length limits, which differ sharply by
post type: **1024 characters for a photo/video/media-group caption** versus
4096 for a plain `sendMessage`. This is not theoretical — a real bilingual
post measured 1115 characters, which Telegram would have rejected outright,
failing every media attempt before silently degrading to text-only via the
fallback ladder. The limit applies to the *parsed* text, so HTML tags don't
count toward it, and a margin is kept because some clients count emoji as
two characters.

Shrinking is ordered by what's least missed: drop hashtags, then give each
language section an equal share of what the fixed parts leave over,
trimming its body at a word boundary with an ellipsis. It never blind-cuts
the assembled string, which could sever a `<b>` tag and break parsing for
the whole message.

## Publishing fallback ladder

`PublishToTelegramJob::attempt`, in order:

```
MediaGroup fails      → single image (best remaining candidate)
Video send fails      → single image (thumbnail or best remaining candidate)
Single image fails    → next remaining candidate, then the next, ...
Every candidate fails  → sendTextOnly
```

Each step catches `TelegramApiException` specifically (network/API-level
failures) and logs a warning before falling through; it does not swallow
unexpected exceptions. If `sendTextOnly` itself fails (e.g. bad bot token,
channel doesn't exist), the job fails for real and goes through normal
Laravel retry/backoff (`$tries = 4`, `$backoff = [15, 60, 300, 900]`) before
landing in `failed_jobs` — a total inability to post at all is a real
failure that should page someone, unlike "the second-best image 404'd."

## Fan-out completion tracking (`MediaPipelineProgressTracker`)

`ExtractMediaJob` can fan out a mix of `DownloadMediaJob` (queue
`media-download`) and `ProcessVideoJob` (queue `video-processing`) for a
single article, and `AnalyzeMediaForNewsItemJob` must run exactly once,
after all of them finish. The obvious tool, `Illuminate\Support\Facades\Bus::batch()`,
turned out to be the wrong one: `Bus\Batch::add()` pushes every job in a
batch through `bulk($jobs, $data, $queue)` using **one** queue name — the
batch's own `->onQueue()` option, or the connection default if that's unset
— and ignores each individual job's own `onQueue()` call entirely. Batching
`DownloadMediaJob` and `ProcessVideoJob` together would have silently
collapsed them onto the same queue, defeating the video-processing
isolation this whole section is about.

Instead, `MediaPipelineProgressTracker` is a plain atomic cache counter:
`ExtractMediaJob` calls `start($newsItemId, $expectedJobCount)`, and every
fanned-out job calls `complete($newsItemId)` exactly once — on success, or
from its `failed()` hook once retries are exhausted — using
`Cache::decrement()`. The call that brings the counter to zero dispatches
`AnalyzeMediaForNewsItemJob`. This has no opinion about which queue any job
runs on, so it composes cleanly with per-job-type queue isolation.

## Failure handling summary

Every job that touches the network or an external process
(`DownloadMediaJob`, `ProcessVideoJob`, `PublishToTelegramJob`,
`AnalyzeMediaForNewsItemJob`, `SelectMediaForPublishingJob`,
`ExtractMediaJob`) declares `$tries`/`$backoff`/`$timeout` and a `failed()`
hook that writes a `media_processing_logs` row and, where relevant, flips
the asset to `status=failed` with `failure_reason` set. `media_processing_logs`
is append-only and indexed on `(media_asset_id, stage)` and `(stage, status)`
specifically so "why did this asset never get published" is a single query,
not a log-grepping exercise.

## Queues & concurrency

Named queues (`config('media.queues')`) map one-to-one to the worker pools
in the spec:

| Queue | Job(s) | Resource profile |
|---|---|---|
| `media-extraction` | `ExtractMediaJob` | Fast, CPU-light (DOM parsing). |
| `media-download` | `DownloadMediaJob` | Network I/O bound, small memory footprint (images/documents only, capped at `max_image_download_bytes`/`max_document_download_bytes`). |
| `video-processing` | `ProcessVideoJob` | **Isolated.** ffmpeg probing/transcoding is CPU- and memory-heavy and can run for minutes; must never share a pool with the fast queues above or a burst of large videos starves everything else. |
| `image-analysis` | `AnalyzeMediaForNewsItemJob` | Bursty CPU (scoring) + occasional model calls if a real `VisionRelevanceAnalyzerInterface` is bound. |
| `media-optimization` | `GenerateMediaVariantJob` | CPU-bound (image encode), short-lived. |
| `media-selection` | `SelectMediaForPublishingJob` | Mostly CPU (variant generation) plus a DB read of the event pool. |
| `telegram-publishing` | `PublishToTelegramJob` | Network I/O bound, must stay low-latency — never let it queue behind a video transcode. |

This repo does **not** include Laravel Horizon: Horizon requires
`ext-pcntl`, which does not exist on Windows PHP builds at all, so it can't
even be dev-tested in this environment. On a Linux production host, Horizon
is a reasonable upgrade — it gives you per-queue supervisor definitions,
auto-balancing, and a dashboard, and every queue name above maps directly to
a Horizon supervisor. Until then, run plain `queue:work` processes under
Supervisor (or systemd), one program block per pool, e.g.:

```ini
[program:media-fast-worker]
command=php artisan queue:work --queue=news-fetch,news-parse,media-extraction,media-download,media-processing,image-analysis,media-optimization,media-selection --tries=3 --max-time=3600
numprocs=4

[program:media-video-worker]
command=php artisan queue:work --queue=video-processing --tries=2 --timeout=900 --max-time=3600
numprocs=1   ; deliberately small and separate — see the isolation note above

[program:telegram-publish-worker]
command=php artisan queue:work --queue=telegram-publishing --tries=4 --max-time=3600
numprocs=2
```

The video pool gets its own `numprocs` and its own `--timeout`, and is never
listed alongside the fast queues in the same `queue:work` invocation — that
single rule is what "isolated worker pool" means in practice without Horizon.

## Never block news ingestion

Every stage above is a queued job (`ShouldQueue`), dispatched from wherever
the news pipeline finishes saving an article — never called synchronously
from an HTTP request or inline during article parsing. `ExtractMediaJob`
itself fans out further jobs and returns; nothing in this pipeline holds an
HTTP request or a news-ingestion worker open waiting on a download or an
ffmpeg process.

## Copyright, licensing, and attribution

`sources.media_reuse_permitted` is the single switch this codebase uses to
decide whether we're allowed to copy an asset's bytes at all
(`MediaIngestionPolicy`, `VideoDecisionService`) — everything defaults to
**reference-only** unless a source has been explicitly marked as permitting
reuse. This is a conservative default, not a legal opinion: a real
deployment needs an actual licensing/legal review per source (and often per
content type — a wire-service photo license may cover editorial use but not
a video), and that determination should feed `media_reuse_permitted` (and
ideally a more granular per-type/per-usage field, if your licenses
distinguish) rather than being re-litigated in code. Whatever the source,
the original `original_url` and `source_id` are always retained on
`media_assets` so attribution can be rendered wherever it's legally
required, and platform-specific content (YouTube, Twitter/X, Instagram,
TikTok, Facebook — see `media.video.reference_only_providers`) is never
downloaded at all, only ever linked, in line with those platforms' terms.
