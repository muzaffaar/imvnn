# Media Pipeline Architecture

This document explains the *why* behind the implementation in `app/Services/Media`,
`app/Services/Telegram`, `app/Jobs/Media`, `app/Jobs/Telegram`, and the
`media_*` migrations. Code comments cover local detail; this file covers
cross-cutting decisions that don't belong in any one file.

## Scope of this codebase

This repo implements the **media pipeline** on top of a deliberately minimal
stand-in for the broader news pipeline (`sources`, `news_items`, `events`,
`telegram_channels`). Event clustering, news scoring, and "is this article a
publication candidate" are out of scope — `NewsItem::event_id` and
`TelegramChannel` exist just deep enough for the media pipeline to be
exercised end-to-end (see the smoke test pattern used during development).

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
command=php artisan queue:work redis --queue=media-extraction,media-download,image-analysis,media-optimization,media-selection --tries=3 --max-time=3600
numprocs=4

[program:media-video-worker]
command=php artisan queue:work redis --queue=video-processing --tries=2 --timeout=900 --max-time=3600
numprocs=1   ; deliberately small and separate — see the isolation note above

[program:telegram-publish-worker]
command=php artisan queue:work redis --queue=telegram-publishing --tries=4 --max-time=3600
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
