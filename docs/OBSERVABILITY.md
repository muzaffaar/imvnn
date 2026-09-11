# Pipeline observability

The news, media, AI, and Telegram pipeline writes structured Laravel events
whose message starts with `pipeline.`. Events contain stable IDs and counts,
not article bodies, AI prompts, HTTP request bodies, or credentials. URLs are
reduced to scheme, host, and path; known API keys, bearer tokens, and Telegram
bot tokens are redacted before they are written.

## What is logged

| Area | Normal operation | Failure / diagnostic detail |
| --- | --- | --- |
| Queue | Optional per-job start and completion, including queue, job class, attempt, and duration | Every retry and final failure, including exception type and a redacted reason |
| Sources | Fetch start/end, RSS format/item count/304, crawl page and candidate counts | HTTP/invalid XML/invalid XPath/unsupported fetcher failures |
| Article ingestion | Created `news_item_id`, source, publication time, media hand-off | Optional skip reason; article fetch and AI fallback failure |
| Media | Extraction/download/video/analysis/selection result and IDs | Duplicate decision, missing progress state, corrupt image, storage/ffmpeg/extractor failure |
| AI | Provider, model, operation, timeout, output limit, token usage, and duration | Transport and invalid response reason; prompts and responses are never logged |
| Telegram | Scheduler backlog/delay, selected plan, delivery strategy/message ID | Invalid channel, claim conflict, API rejection/rate limit, fallback, and delivery-unknown result |

The useful correlation fields are `source_id`, `source_slug`, `news_item_id`,
`media_asset_id`, `telegram_channel_id`, `queue`, `job_id`, and `attempt`.

## AlmaLinux production settings

Set these in `/var/www/imvnn/.env` before caching the Laravel configuration:

```dotenv
LOG_CHANNEL=daily
LOG_STACK=daily
LOG_LEVEL=info
LOG_DAILY_DAYS=30

# Keep disabled in steady state: it adds one start and one completion record
# for every queued App\Jobs job.
PIPELINE_LOG_QUEUE_LIFECYCLE=false

# Enable temporarily when diagnosing why a source discovers articles but does
# not create NewsItem rows.
PIPELINE_LOG_CANDIDATE_SKIPS=false
```

Apply an `.env` change and restart the workers:

```bash
cd /var/www/imvnn
sudo -u imvnn /usr/bin/php artisan config:cache
sudo supervisorctl restart imvnn:*
```

Replace `imvnn` with the actual Linux service user. It must exist: a
Supervisor error such as `Invalid user name imvnn` is a server-account setup
error, not a Laravel failure. The same user needs write access to
`/var/www/imvnn/storage` and `/var/www/imvnn/bootstrap/cache`.

## Watch logs live

Laravel's structured application log is separate from Supervisor's process
output. With the production settings above, use:

```bash
tail -F /var/www/imvnn/storage/logs/laravel-$(date +%F).log
```

Use Supervisor logs to see worker startup, worker exits, and Artisan's queue
console output:

```bash
tail -F /var/log/imvnn/pipeline.log
tail -F /var/log/imvnn/video.log
tail -F /var/log/imvnn/telegram.log
tail -F /var/log/imvnn/scheduler.log
```

Show only failures/retries from today's structured log:

```bash
grep -E 'pipeline\.(queue\.job_(retrying|failed)|news\..*(failed|invalid|error)|media\..*failed|telegram\..*(failed|unknown|rejected)|ai\.request_failed|http\..*failed)' \
  /var/www/imvnn/storage/logs/laravel-$(date +%F).log
```

For a short investigation, set `LOG_LEVEL=debug` and enable either optional
switch above, then rebuild config and restart the workers. Turn them off when
finished so high-volume source polling does not consume unnecessary disk.

## Queue topology and health

`App\Enums\QueueName` is the source of truth for queue names and expected
worker pools. The Supervisor configuration assigns the fast `pipeline` pool
to news fetching/parsing, all non-video media stages, and `media-selection`;
the video and Telegram publishing queues remain isolated.

After every Supervisor configuration change, run:

```bash
cd /var/www/imvnn
/usr/bin/php artisan queue:health --stuck-after=60
```

The command ignores reserved and intentionally delayed jobs. It fails only
when a configured queue has available, unreserved work older than the chosen
threshold, which normally means that its Supervisor worker is stopped or not
listening to that queue.

## First response to a failed job

1. Find `pipeline.queue.job_failed` or `pipeline.queue.job_retrying` and note
   `job_class`, `job_id`, `attempt`, and its redacted `reason`.
2. Search the same log for that `news_item_id`, `media_asset_id`, or
   `source_id` to find the stage that failed first.
3. Inspect the durable processing history when it is a media or publishing
   failure:

   ```bash
   cd /var/www/imvnn
   /usr/bin/php artisan tinker
   >>> App\Models\MediaProcessingLog::latest()->limit(20)->get()
   ```

4. After correcting configuration or external access, retry only the affected
   failed job with Laravel's normal queue tooling; do not start a second
   scheduler chain unless that is intentional.
