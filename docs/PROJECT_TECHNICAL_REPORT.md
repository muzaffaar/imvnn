# IMVNN News Pipeline — Technical Report

**Repository basis:** current Laravel source, configuration, migrations, jobs,
Supervisor definition, commands, tests, and operational documentation. This
report describes implemented behavior only. Live database volume, active server
configuration, source availability, and delivery history are **not determinable
from the repository**.

## 1. Executive Summary

IMVNN is a queue-driven AI-news aggregation and Telegram publishing backend.
It polls curated official/vendor sources, discovers and parses articles, keeps
fresh AI-related items, extracts/ranks media, and publishes paced Telegram
posts. Primary users are content operations staff and the platform/DevOps team.

~~~
Configured source -> fetch -> parse/AI relevance -> NewsItem -> media pipeline
-> publishing scheduler -> selection/caption -> Telegram Bot API
~~~

The asynchronous workflow, retries, durable database state, media fallback, and
structured logs are production-oriented. It is not a complete management
product: no management UI/API exists beyond Laravel's default welcome route;
event clustering is not implemented; and vision/embedding extension points are
currently no-op bindings.

## 2. System Architecture

| Component | Implemented responsibility | Evidence |
| --- | --- | --- |
| Laravel | CLI/queue-centric app; web route returns welcome | routes/web.php, routes/console.php |
| Database | Models/migrations use PostgreSQL-compatible JSON/JSONB; deployment docs specify PostgreSQL | database/migrations, docs/DEPLOYMENT.md |
| Queue | Laravel database queue by default; names centralized | config/queue.php, app/Enums/QueueName.php |
| Scheduler | news:fetch every five min by default; queue health every five min | routes/console.php, config/news_sources.php |
| Runtime | Supervisor pipeline, isolated video, Telegram, scheduler processes | deploy/supervisor/imvnn.conf |
| HTTP | Bounded Guzzle fetcher with limits, redirects, validators, source headers | app/Services/Http/BoundedHttpFetcher.php |
| AI | Gemini native or OpenAI-compatible structured output | app/Services/Ai, app/Providers/AiServiceProvider.php |
| Media | extraction, storage, scoring, variants, video processing | app/Jobs/Media, app/Services/Media |
| Telegram | channel registry, paced scheduler, durable claims, fallbacks | app/Jobs/Telegram, app/Services/Telegram |

ArticleAnalyzerInterface resolves to FallbackArticleAnalyzer and
CaptionComposerInterface to FallbackCaptionComposer. VisionRelevanceAnalyzerInterface
and EmbeddingSimilarityDetectorInterface resolve to no-op implementations.
Text LLM functions are therefore optional and real; vision/embedding ML is not
active.

## 3. News Source Management

Source (app/Models/Source.php) maps to sources: name, unique slug, type,
0–100 reliability, reuse permission, fetch type/URL, JSON fetch_options,
active state, last_fetched_at, RSS ETag, and Last-Modified. news-sources:sync
upserts config/news_sources.php by slug, making configuration the curated-source
authority while timestamps/validators remain runtime data.

Current configured sources are MIT News Robotics; Google Blog; Anthropic News;
Hugging Face Blog; Mistral AI News; MIT News AI; MIT News All; Google Research
Blog; MIT Technology Review AI; Google DeepMind News; AWS Machine Learning
Blog; NVIDIA AI Blog; Meta AI Blog; Cohere Blog; and Stability AI News.

Implemented types:

- rss: RSS 2.0/Atom parsing, summary/date/media preservation, ETag and
  Last-Modified conditional polling (RssSourceFetcher).
- html_crawl: listing download, same/allowed-host link discovery, URL heuristic
  or XPath, then individual article fetch (HtmlCrawlSourceFetcher).

fetch_options supports allowed hosts, URL include/exclude globs, excluded path
segments, article/next-page XPath, max_pages (1–10), max_links (1–100),
prefilter bypass, RSS-summary fallback, and validated headers. Meta/Cohere/
Stability use XPath and allowlists. Add a source to config/news_sources.php,
then run:

~~~bash
php artisan news-sources:sync
~~~

## 4. News Fetching and Parsing Pipeline

| Step | Code / queue | Input -> output | Failure/state behavior |
| --- | --- | --- | --- |
| Dispatch | news:fetch -> FetchNewsSourceJob; news-fetch | active Source -> one job/source | warns/logs when no source |
| Fetch | NewsSourceFetcherManager; RSS/HTML fetcher | feed/listing -> RawArticleCandidate collection | 3 tries, 60 s, 30/120/600; transient HTTP retries, permanent 4xx terminal |
| Parse | ProcessNewsCandidateJob; news-parse | candidate -> NewsItem or skip | 3 tries, 30 s, 15/60/300; isolated per article |
| Persist | NewsIngestionService | candidate/source -> stored article | deterministic exclusions are normal skips |
| Handoff | ExtractMediaJob; media-extraction | new item -> media pipeline | only after insertion |

RSS is capped at 20 items and 5 MiB; preserves encoded content, enclosures,
media:content, and thumbnails. HTML crawl resolves relative URLs, limits
HTTP(S)/host/path shape, and can follow configured pagination. Both use 15 s
response, 5 s connect, and 5 MiB fetch limits.

NewsIngestionService::ingest() normalizes URLs with UrlNormalizer, rejects
canonical duplicates, applies prefilter unless bypassed, rejects stale RSS
dates before article HTTP, fetches article HTML or a configured RSS-summary
fallback, extracts title/content/date, applies freshness, invokes the analyzer,
and creates a UUID NewsItem with raw HTML and RSS payload.

ArticleContentExtractor prioritizes og:title, title, then h1; strips
script/navigation/footer; extracts article paragraphs or dense paragraph
containers; caps content at 8,000 chars; and gets dates from meta, time,
JSON-LD, then visible text. FreshnessPolicy defaults to source-publication day
in Asia/Tashkent, converted to UTC. Unknown dates are not fresh.

A discovered article is skipped when duplicate, prefilter-ineligible, stale or
undated, article-unavailable without permitted summary fallback, parse/freshness
failure, missing analysis title, or not AI-related. Candidate-skip logs are
optional (PIPELINE_LOG_CANDIDATE_SKIPS).

## 5. AI / ML / LLM Pipeline

### Deterministic/rule-based functions

AiRelevanceFilter is a Unicode keyword-regex gate, used before article fetch
when possible and by HeuristicArticleAnalyzer. Article parsing, media relevance/
quality, Telegram compatibility, dHash duplicate detection, and caption budget
are deterministic. NullVisionRelevanceAnalyzer returns the existing score;
NullEmbeddingSimilarityDetector means semantic embedding deduplication is not
implemented.

### Article LLM analysis

AiArticleAnalyzer sends title plus HTML-stripped normalized article text capped
at AI_ANALYSIS_MAX_INPUT_CHARS (12,000 default). Its prompt asks for substantive
AI relevance, clean title, neutral paraphrased summary. Required schema:

~~~json
{"is_ai_related": true, "title": "string", "content": "string"}
~~~

Defaults are temperature 0.1, 500 output tokens, 20 seconds. GeminiStructuredOutputClient
uses Gemini JSON-schema output; OpenAiCompatibleStructuredOutputClient supports
json_schema, json_object, or no provider schema. Both parse JSON and log
provider/model/token/duration, not prompts or bodies.

FallbackArticleAnalyzer enables LLM use only when enabled with provider
credentials (or openai-compatible). Timeout, quota/rate limit, malformed JSON,
missing fields, and all other errors log news.analysis_ai_fallback then use
HeuristicArticleAnalyzer. No confidence threshold, batching, global spend
budget, or evaluation corpus exists.

### Caption LLM

AiCaptionComposer sends stored title, content capped at 4,000 chars, and media
type. It requests Uzbek Latin-script headline/body in short, ordinary language
and 2–4 lowercase hashtags; no humor field is requested. PostHeader appends a
validated direct original-article link, placed immediately before hashtags, and
intentionally omits the source name, publication date, and time. It falls back
to `url` when `canonical_url` is malformed, validates script/length, escapes HTML, and fits 1,024 Telegram
caption chars. Defaults: temperature 0.7, 400 output tokens, 20 seconds.

FallbackCaptionComposer only uses PlainCaptionComposer after LLM failure when
TELEGRAM_CAPTION_FALLBACK_ORIGINAL=true. Default false avoids publishing likely
English source text. Caption LLM use is one call per selection attempt, not per
fetched candidate.

## 6. Relevance and Ranking

Article relevance is acceptance/rejection. At publishing time,
NewsPriorityScorer applies transparent title/body signals for urgent updates,
breakthroughs, launches, safety/security, and material business or policy
changes. It ranks these ahead of routine eligible news without generating
sensational language. `min_news_priority_score` defaults to `0.20`, so routine
updates are filtered; lower it to `0.0` for a channel that should publish all
otherwise eligible news. Source reliability contributes to media quality only.

MediaRelevanceScorer weights keyword overlap 0.4, position 0.3, extractor trust
0.3. Up to three assets with pivot relevance at least 0.35 reach the current
no-op vision interface; a real result would blend 40% rule / 60% vision.
MediaQualityScorer weights relevance 25%, resolution 15%, visual proxy 15%,
source reliability 15%, Telegram compatibility 10%, uniqueness 10%, aspect
ratio 10%.

PublishNextReadyNewsItemJob::eligibleQuery() requires
media_analysis_completed_at, null publish_queued_at, null telegram_published_at,
and current-day freshness when enabled. It orders priority score, maximum
ready-media quality (null last), then oldest analysis. Stored articles can remain
unselected because not analyzed, stale, claimed/published, lower ranked,
channel-inactive, or absent scheduler chain.

events/event_media exist and selection can use an assigned event pool. No event
creation, clustering, semantic article deduplication, or assignment to
news_items.event_id exists.

## 7. Database Specification

| Table | Purpose / important state |
| --- | --- |
| sources | Source config and runtime fetch validators/state |
| news_items | UUID article, source/event FK, canonical URL, raw HTML/RSS payload, content/date, publishing state; canonical URL unique |
| media_assets | UUID reusable asset: URL/storage/hash/dimensions/status/duplicate/quality |
| news_media | Article-asset pivot: per-article relevance, role, position, featured |
| media_variants | Derived original/optimized/thumbnail/Telegram/compressed rendition |
| media_metadata | Typed JSON technical metadata |
| media_processing_logs | Append-only stage audit: status, message/context, duration, attempt |
| events, event_media | Shared-media schema only; automatic event lifecycle absent |
| telegram_channels | chat identity, active state, JSON rules, chain token |
| jobs, failed_jobs, cache | Laravel queue, failures, cache infrastructure |

State semantics:

- media_analysis_completed_at: set after media analysis; scheduler eligibility.
- publish_queued_at: atomic scheduler claim before selection; released after
  terminal selection/publishing recovery.
- telegram_publish_started_at: durable pre-send claim; cleared for known retry
  and terminal unpublished failure recovery.
- telegram_published_at: set only after valid Bot API receipt; no-repeat marker.

## 8. Media Pipeline

ExtractMediaJob (3 tries; 10/60/300) uses metadata, RSS, API, HTML extractors
and a YouTube adapter. It applies MediaIngestionPolicy, canonical URL dedup,
creates MediaAsset, and attaches news_media with contextual relevance.

DownloadMediaJob (3 tries, 60 s, 10/60/300) bounds image/document download,
hashes content, detects exact/dHash duplicates, uses the configured public disk,
extracts image metadata/sharpness, and marks ready/failed. ProcessVideoJob
(2 tries, 900 s, 30/300) probes/downloads/transcodes only when policy allows,
optionally fetches a thumbnail, and records ffprobe metadata.

MediaPipelineProgressTracker waits for download/video fan-out—including terminal
asset failures—before AnalyzeMediaForNewsItemJob scores assets, syncs any
already-assigned event media, and sets media_analysis_completed_at.

MediaSelectionService gathers article/event media, rejects unusable formats/
dimensions/quality, probes up to eight leading visual candidates, removes
renditions, applies min_quality_score (default 0.35), then chooses compatible
downloaded video, video-thumbnail fallback, single image, album (default max
four / Telegram max ten), or none. Selected downloaded images get lazy
Telegram variants. Missing/failed media permits another asset or text-only.

## 9. Telegram Publishing Pipeline

telegram:channel verifies chat existence, bot administrator status, and posting
rights. publishing:start {id} creates a UUID chain token and dispatches
PublishNextReadyNewsItemJob on media-selection. It is one-shot bootstrap, not
a service. It must not run in Supervisor with autorestart=true: it exits
immediately and relaunch would continually mint chains. The supplied Supervisor
file correctly has no bootstrap program.

The scheduler has one try and reschedules in finally. Token comparison retires
old chains. Default baseline is 30 minutes; backlog delay is random from 15
minutes to a decreasing ceiling, saturated at five items.

SelectMediaForPublishingJob queues PublishToTelegramJob (4 tries; 15/60/300/900).
Fallback ladder:

~~~
media group -> each usable single image -> text-only
video -> thumbnail/image -> text-only
single image -> next candidate -> text-only
~~~

TelegramPublisher sends sendMediaGroup, sendPhoto, sendVideo, sendMessage.
HTTP 400 media errors including WEBPAGE_CURL_FAILED, WEBPAGE_MEDIA_EMPTY,
PHOTO_*, VIDEO_*, IMAGE_*, FILE_*, MEDIA_*, and failed HTTP URL content become
mediaRejected, so fallback continues. HTTP 429 releases claim/delays by API
retry time. Valid receipt sets telegram_published_at; known retries release
delivery claim; terminal failed() releases unpublished claims.

## 10. Queue Architecture

| Queue | Jobs | Runtime policy / workload | Supervisor pool |
| --- | --- | --- | --- |
| news-fetch | FetchNewsSourceJob | 3 tries; 60 s; source I/O | pipeline (2) |
| news-parse | ProcessNewsCandidateJob | 3 tries; 30 s; article parse/analysis | pipeline |
| media-extraction | ExtractMediaJob | 3 tries; extractor fan-out | pipeline |
| media-download | DownloadMediaJob | 3 tries; 60 s; bounded media I/O | pipeline |
| media-processing | no current dispatcher | reserved configured queue | pipeline |
| image-analysis | AnalyzeMediaForNewsItemJob | 3 tries; scoring | pipeline |
| media-optimization | GenerateMediaVariantJob | 3 tries; image encode | pipeline |
| media-selection | scheduler and selection job | DB/caption/variant work | pipeline |
| video-processing | ProcessVideoJob | 2 tries; 900 s; ffmpeg/CPU | video |
| telegram-publishing | PublishToTelegramJob | 4 tries; Bot API | Telegram |

DB_QUEUE_RETRY_AFTER must exceed the 900-second video timeout; deployment uses
960 seconds.

## 11. Scheduler and Supervisor

Supervisor runs imvnn-pipeline (two workers), imvnn-video, imvnn-telegram, and
imvnn-scheduler (schedule:work), all autostart/autorestart. It expects
/var/www/imvnn, /usr/bin/php, Unix user imvnn, and /var/log/imvnn.

Safe deployment from docs/DEPLOYMENT.md: deploy code; Composer install; migrate
--force; configure environment; config:cache; news-sources:sync; install
Supervisor file; supervisorctl reread/update; restart worker/scheduler
processes; verify status; run publishing:start <channel-id>; queue:health.
Do not additionally run schedule:run cron when schedule:work is supervised.

## 12. Error Handling and Observability

PipelineLogger writes redacted pipeline.* events. It removes configured AI/
Telegram secrets, bearer tokens, query secrets, and Bot API token URLs. It logs
IDs/counts, not prompts or article content.

Events cover source start/completion/failure, HTTP classification, RSS/crawl
diagnostics, ingestion, AI fallback/token use, media extraction/download/video/
analysis/selection, scheduler/claim/publish/fallback/rate-limit/delivery
outcome, and queue retries/failures through AppServiceProvider.

Trace a news_item_id through Laravel logs, media_processing_logs by article/
asset, the four publishing timestamps, jobs/failed_jobs, and telegram_channel_id.
Queue lifecycle success logs are optional; failures/retries are always logged.
queue:health detects ready/unreserved known-queue work past threshold.

## 13. Configuration / Environment Variables

| Category | Required production settings | Optional/tunable |
| --- | --- | --- |
| Database/queue | DB_*, QUEUE_CONNECTION=database, DB_QUEUE_RETRY_AFTER=960 | Redis driver supported but not supplied topology |
| Telegram | TELEGRAM_BOT_TOKEN; registered/seeded channel | API URI, channel rules/timing/quality |
| AI | API key for Gemini/hosted provider | provider/model/base URI/schema/limits/timeouts |
| HTTP/news | source config | HTTP_FETCH_USER_AGENT, source options, cadence, keywords, freshness |
| Media/storage | public MEDIA_DISK | S3-compatible credentials, caps, ffmpeg, score/dedup |
| Observability | logging destination/retention | lifecycle/candidate-skip verbosity |

Evidence: .env.example; config/services.php; config/news_sources.php;
config/media.php; config/queue.php; config/filesystems.php; config/observability.php.
Secrets are not present in this report.

## 14. Deployment and Runtime Flow

Production assumes PHP/Laravel CLI, Composer, PostgreSQL, database queue,
Supervisor, schedule:work, and public object storage for downloadable Telegram
media. Nginx/PHP-FPM is not required by the pipeline itself; the only checked-in
web route is the Laravel welcome route. Storage and bootstrap/cache must be
writable by the Supervisor user. Use config:cache after environment changes and
restart long-lived workers after deploy.

## 15. End-to-End Example

1. schedule:work invokes news:fetch and dispatches FetchNewsSourceJob for AWS ML.
2. RssSourceFetcher conditionally downloads the feed and emits a candidate.
3. ProcessNewsCandidateJob normalizes URL, checks prefilter/freshness, fetches/
   parses article, then calls LLM or heuristic analyzer.
4. Accepted item is inserted; ExtractMediaJob is queued.
5. Extractors attach news_media; download/video completion triggers analysis.
6. AnalyzeMediaForNewsItemJob sets media_analysis_completed_at.
7. Scheduler ranks candidate, atomically sets publish_queued_at, queues selection.
8. Selection ranks media, generates variant, generates Uzbek caption.
9. PublishToTelegramJob claims telegram_publish_started_at and attempts media.
10. A media rejection falls through candidates and ultimately text-only.
11. Valid delivery sets telegram_published_at; terminal failure logs/release
    claims for paced recovery.

## 16. Current Strengths

- RSS and configurable HTML source abstraction.
- Bounded fetching, conditional feed requests, canonical URL uniqueness.
- Per-stage queue isolation, especially video and Telegram.
- Durable publishing claims, scheduler tokens, media/text fallback.
- Structured secret-safe logs and media processing audit trail.
- Structured, provider-neutral LLM calls with deterministic article fallback.

## 17. Current Risks / Technical Debt

| Severity | Evidence / impact | Recommended fix |
| --- | --- | --- |
| High | Null vision/embedding bindings: no real media ML/semantic dedup | Implement measured providers or remove ML expectations |
| High | events/event_media exist but no clustering/assignment code | Build assignment with confidence/review controls |
| High | uncertain Telegram delivery can later retry after terminal recovery | Add message reconciliation or explicit hold/retry policy |
| Medium | HTML crawl depends on publisher markup/XPath | source health metrics, fixtures, adapters |
| Medium | no cumulative AI budget/prompt version/evaluation corpus | budgets, prompt versioning, labelled evaluation |
| Medium | reference-only media depends on third-party public URLs | prefer licensed stored assets; measure URL failures |
| Medium | media-processing queue has no current dispatcher | remove it or assign work |
| Low | no management UI/API | add only if operations require it |
| Low | live source/channel metrics not in repository | metrics/dashboard/deployment inventory |

## 18. AI-Specific Improvement Roadmap

**Short term:** AI daily/token budgets; prompt versions; logged fallback rates;
labelled relevance/caption evaluation examples.

**Medium term:** calibrated article importance/relevance; real vision provider
behind existing interface; semantic dedup/event clustering with measured
thresholds; model routing for cheap versus borderline candidates.

**Advanced ML/MLOps:** offline regression evaluation, embeddings, breaking-news
detection, multilingual quality evaluation, hallucination checks against source
text, model/version/cost dashboards.

## 19. News Parsing Improvement Roadmap

Add source success/error/latency/candidate-count metrics; fixtures for each
crawler; date extraction regression tests; source-specific parsers for weak
sites; robots/rate-policy handling; redirect-aware security review; stronger
canonicalization; source-specific backoff; and alerts for stale
last_fetched_at or failed queues.

## 20. Management Summary

The project automatically ingests curated AI news, parses/stores fresh articles,
processes/selects media, optionally generates Uzbek captions with an LLM, and
publishes paced Telegram posts under Supervisor. Its AI is text relevance/
summarization/caption generation; image relevance and embeddings are not active
ML today. Near-term priorities are production monitoring, uncertain-delivery
reconciliation, source parser reliability, and measured event/vision/embedding
work.

| Area | Current status | Maturity | Main risk | Next action |
| --- | --- | --- | --- | --- |
| News ingestion | RSS/HTML queue flow implemented | High | upstream variability | source health metrics |
| Parsing | heuristic content/date extraction | Medium | markup/date fragility | fixtures/adapters |
| AI analysis | optional structured LLM + fallback | Medium | no cost/eval governance | budgets/evaluation |
| Media processing | extraction/download/variants/video isolation | Medium | external media/licensing | measure/store licensed media |
| Ranking | deterministic media quality | Medium | no article importance model | calibrate outcomes |
| Telegram publishing | claims/tokens/fallbacks | Medium | uncertain delivery | reconciliation |
| Queues | named topology/health command | High | deployment drift | config enforcement/alerts |
| Observability | redacted logs/media audit | Medium | no metrics dashboard | metrics/alerts |
| Deployment | Supervisor definition/docs | Medium | live state external | CI/deployment checks |
| Scalability | database queue/two normal workers | Medium | DB/LLM/media throughput | load test; Redis/Horizon if justified |

## Appendix: inspected areas

- app/Jobs/{News,Media,Telegram}; app/Services/{News,Media,Ai,Telegram,Http}
- app/Models, app/Providers, app/Console/Commands, PipelineLogger
- config/news_sources.php, media.php, services.php, queue.php, filesystems.php,
  observability.php; .env.example
- database/migrations; routes/console.php; routes/web.php;
  deploy/supervisor/imvnn.conf
- tests/Feature, tests/Unit, docs
