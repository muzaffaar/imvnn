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
- Publishing state is global to a news item, not per channel. Independent delivery of each story to several channels requires a publication ledger keyed by channel and news item.
- Scheduler tokens are not consumed per execution; duplicate executions with the same token can still fork a scheduler chain. Individual item claims prevent duplicate articles, but channel cadence is not guaranteed at actual send time.
- AI prompts request factual summaries, but no independent factual verifier checks names, numbers, quotations or translation accuracy. Script validation alone cannot establish language correctness. Humor and tone remain configurable existing policy.
- Article date fallback can still mistake a visible event/update date for publication time; canonical links and redirects are not reconciled with publisher-declared article identity.
- Media selection is heuristic and probing is bounded to eight candidates. Semantic relevance, watermark detection, near-duplicate reference images and best-HD-video discovery are not guaranteed.
- Source names are already included by the caption header; article attribution links and Telegram buttons remain absent by existing design.

## Validation

`php artisan test` covers queue redelivery/claims, uncertain outcomes, rate-limit delays, Telegram response classification, caption entities/length, lazy image extraction, boilerplate exclusion and rendition quality selection. HTTP and publishing are mocked; tests send no real posts.

Protocol reference: [Telegram Bot API](https://core.telegram.org/bots/api), including `ResponseParameters.retry_after` and message/media response shapes.
