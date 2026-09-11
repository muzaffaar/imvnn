# Deployment (AlmaLinux)

This pipeline has **no HTTP surface** — it is entirely queue- and CLI-driven.
No nginx/Apache, no PHP-FPM, no `artisan serve`. What it needs is PHP CLI,
PostgreSQL, supervisor for the workers, and cron for the scheduler.

Redis is optional. The queue runs on the `database` driver, which is fine at
this volume (a few hundred jobs an hour) and removes a moving part.

## 1. Requirements

```bash
php -v                 # 8.2+
php -m | grep -E 'pdo_pgsql|gd|mbstring|fileinfo|dom|simplexml|curl|openssl'
```

`gd` backs Intervention Image (perceptual hashing, variant generation),
`pdo_pgsql` is required — the schema uses `jsonb` — and `dom`/`simplexml`
back article parsing and RSS. `exif` is optional; the code guards for it.

`ffmpeg`/`ffprobe` are only needed if a source ever yields a downloadable
video. Every source configured so far is reference-only, so the video path
stays idle — install them when you add a source that isn't.

## 2. Database

```bash
sudo -u postgres createuser imvnn --pwprompt
sudo -u postgres createdb imvnn --owner=imvnn
```

## 3. Application

```bash
cd /var/www/imvnn
composer install --no-dev --optimize-autoloader

cp .env.example .env
php artisan key:generate
```

Fill in `.env`: `DB_*`, `TELEGRAM_BOT_TOKEN`, `AI_API_KEY`. Keep
`QUEUE_CONNECTION=database` and set `DB_QUEUE_RETRY_AFTER=960` so the
900-second video worker cannot be released and re-run while it is still
processing. Configure `MEDIA_DISK=s3` with a public object URL when downloaded
media may be published; Telegram fetches media by URL rather than from this
server's local disk.

Use the server-side source-fetch identity unless a publisher explicitly
requires a different one. Remove an old Chrome-like
`HTTP_FETCH_USER_AGENT` value if it exists in the deployed `.env`:

```dotenv
HTTP_FETCH_USER_AGENT=imvnn-news-fetcher/1.0
```

The default request headers are deliberately not a browser impersonation.
If one publisher needs an extra header, configure it only on that source in
`config/news_sources.php` under `fetch_options.headers`, then run
`php artisan news-sources:sync`. Source options cannot disable TLS
verification, timeouts, redirect handling, or download-size limits.

### Choose the AI provider

Gemini remains the default and uses its native API:

```dotenv
AI_PROVIDER=gemini
AI_API_KEY=your-gemini-key
AI_MODEL=gemini-2.5-flash-lite
AI_BASE_URI=https://generativelanguage.googleapis.com
```

For OpenAI or a Chat-Completions-compatible service, including a local GPU
server such as vLLM, use:

```dotenv
AI_PROVIDER=openai-compatible
AI_API_KEY=                         # may be empty only for a trusted local server
AI_MODEL=your-model-id
AI_BASE_URI=http://127.0.0.1:8000/v1
AI_OPENAI_PATH=chat/completions
AI_OPENAI_STRUCTURED_OUTPUT=json_schema
AI_OPENAI_MAX_TOKENS_FIELD=max_tokens
```

If that server does not support JSON Schema responses, set
`AI_OPENAI_STRUCTURED_OUTPUT=json_object`; use `none` only as a last resort.
The prompt still requests JSON and the application validates it. Existing
deployments that only use `GEMINI_*` variables continue to work; migrate to
`AI_*` when ready.

Keep the server clock on **UTC**. Timestamps are stored in UTC; they are used
for freshness decisions but are not shown in Telegram post headers. Changing
the system timezone does change what "today" means to `FreshnessPolicy`.

```bash
php artisan migrate --force
```

## 4. Writable paths

```bash
# The Supervisor `user=` must name a real Linux account. This directly fixes
# `ERROR: CANT_REREAD: Invalid user name imvnn` on a new VPS.
id imvnn || sudo useradd --system --home-dir /var/www/imvnn --shell /sbin/nologin --user-group imvnn

sudo install -d -o imvnn -g imvnn -m 0755 /var/log/imvnn
sudo chown -R imvnn:imvnn /var/www/imvnn/storage /var/www/imvnn/bootstrap/cache /var/log/imvnn
```

AlmaLinux ships SELinux enforcing. Supervisor-run CLI processes usually run
unconfined, but if writes to `storage/` fail with permission errors that
`chown` doesn't explain, check `sudo ausearch -m avc -ts recent` before
assuming file modes are wrong.

## 5. Register sources and the channel

```bash
php artisan news-sources:sync
php artisan telegram:channel @your_channel
php artisan publishing:start 1        # id printed by the previous command
```

`telegram:channel` verifies the bot is an administrator with "Post messages"
before writing the row, so a misconfigured bot fails here rather than
silently hours later.

For an unattended provision (CI, image build, config-management run), set
`TELEGRAM_CHANNEL_CHAT_ID` and optionally `TELEGRAM_CHANNEL_NAME` in `.env`
and seed instead:

```bash
php artisan db:seed --class=TelegramChannelSeeder
```

Both paths write the same `rules` (`TelegramChannel::defaultRules()`). The
seeder is safe to re-run: it never overwrites the `rules` of a channel that
already exists, so cadence tuning survives a redeploy. What it *cannot* do
is check anything with Telegram — a mistyped chat id seeds without complaint
and only shows up later as a failed send, which is why the interactive
command is the better choice when a human is present.

## 6. Workers (supervisor)

```bash
cd /var/www/imvnn
# Edit only if the project path or actual Linux service account differs.
sudo install -o root -g root -m 0644 deploy/supervisor/imvnn.conf /etc/supervisord.d/imvnn.ini
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart 'imvnn-pipeline:*' imvnn-video imvnn-telegram imvnn-scheduler
sudo supervisorctl status
```

The installed configuration is the complete worker topology. Do not replace
it with a worker that listens to only a subset of queues:

| Supervisor program | Queues | Purpose |
| --- | --- | --- |
| `imvnn-pipeline` (2 processes) | `news-fetch`, `news-parse`, `media-extraction`, `media-download`, `media-processing`, `image-analysis`, `media-optimization`, `media-selection` | Normal ingestion and media work, including the publishing scheduler and selection. |
| `imvnn-video` | `video-processing` | Isolated 900-second video jobs. |
| `imvnn-telegram` | `telegram-publishing` | Low-latency Telegram API delivery. |
| `imvnn-scheduler` | `schedule:work` | Schedules periodic source fetch and queue-health checks. |

`media-selection` is intentional in `imvnn-pipeline`: it consumes both
`PublishNextReadyNewsItemJob` and `SelectMediaForPublishingJob`. If this
program is absent or uses an old queue list, `publish_queued_at` stays `NULL`
and selection jobs remain at `attempts = 0`.

`stopwaitsecs` in that file is deliberately larger than each queue's longest
job — see the comment at the top of it. Shortening it reintroduces a real
bug: a worker SIGKILLed mid-job leaves the job reserved, Laravel re-runs it
after `retry_after`, and the publishing scheduler ends up with two chains
posting in parallel.

## 7. Scheduler (Supervisor)

The supplied Supervisor configuration includes an `imvnn-scheduler` program
that runs `php artisan schedule:work` continuously. It drives `news:fetch`
every five minutes by default (`NEWS_FETCH_INTERVAL_MINUTES`, backed by
`news_sources.fetch_interval_minutes`) and restarts after a
crash, just like the queue workers.

Do **not** add a `schedule:run` cron entry when using this configuration, or
the fetch runs twice.

## 8. Verify

```bash
php artisan schedule:list                                      # news:fetch and queue:health listed
php artisan queue:health --stuck-after=60                       # configured queues are empty/reserved/delayed
php artisan news:fetch                                           # dispatch every active source now
php artisan tinker --execute='App\Jobs\News\FetchNewsSourceJob::dispatchSync((int) App\Models\Source::where("slug", "meta-ai-blog")->valueOrFail("id"));' # actual Laravel Meta fetch + parser
sudo supervisorctl status                                      # all processes RUNNING
tail -f storage/logs/laravel.log
```

The source-specific Meta command uses the same job, fetcher, and HTML crawler
as the queue worker. It updates `sources.last_fetched_at` and queues any
discovered candidates for `news-parse`; it is not a curl-only check.

Inspect the expected post-fetch state:

```bash
php artisan tinker --execute="
DB::table('sources')->select('id', 'name', 'fetch_type', 'last_fetched_at')->orderBy('id')->get()->each(fn (\$x) => dump(\$x));

DB::table('jobs')->select('queue', DB::raw('COUNT(*) as count'), DB::raw('MAX(attempts) as max_attempts'))->groupBy('queue')->get()->each(fn (\$x) => dump(\$x));

DB::table('news_items')->select('title', 'media_analysis_completed_at', 'publish_queued_at', 'telegram_publish_started_at', 'telegram_published_at')->latest()->limit(20)->get()->each(fn (\$x) => dump(\$x));
"
```

Within a few minutes you should see articles appear:

```bash
php artisan tinker --execute="
echo 'articles: '.App\Models\NewsItem::count().PHP_EOL;
echo 'failed jobs: '.Illuminate\Support\Facades\DB::table('failed_jobs')->count().PHP_EOL;"
```

Nothing posts immediately by design: only articles published **today** are
eligible, and posting is paced at a 2-hour baseline, shortening toward 15
minutes as a backlog builds.

## Deploying an update

Order matters — restart the workers, or they keep running the old code in
memory. This is not theoretical: it once made a fixed Telegram bug look
unfixed for an hour.

```bash
cd /var/www/imvnn
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan news-sources:sync
sudo install -o root -g root -m 0644 deploy/supervisor/imvnn.conf /etc/supervisord.d/imvnn.ini
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart 'imvnn-pipeline:*' imvnn-video imvnn-telegram imvnn-scheduler
sudo supervisorctl status
php artisan publishing:start 1
php artisan queue:health --stuck-after=60
```

The final `publishing:start` is idempotent and re-establishes a single
posting chain: a worker restart can interrupt the scheduler mid-job and
fork it, and re-running mints a token that retires any older chain.

**Never `migrate:fresh` on the server.** It drops `telegram_published_at`,
which is the only thing preventing an already-posted article from posting
again the same day.

## Operating notes

- **Nothing posting?** Check in this order: `supervisorctl status`, then
  whether anything is eligible —
  `NewsItem::whereNotNull('media_analysis_completed_at')->whereNull('telegram_published_at')->count()`
  — then the freshness window: on a quiet news day there may genuinely be
  no articles published today, which is working as intended, not a fault.
- **Cost**: the configured AI provider is called roughly twice per surviving article (analysis +
  caption). Per-call input and output are both capped; there is no
  cumulative budget ceiling, so watch usage via the
  `[ai-analysis]`/`[ai-caption]` token lines in the log.
- **Failed jobs** land in `failed_jobs`; inspect with `php artisan
  queue:failed`. After fixing a source or configuration, retry only the
  verified job UUID(s), for example `php artisan queue:retry <uuid>`; do not
  blindly retry unrelated failed work.
