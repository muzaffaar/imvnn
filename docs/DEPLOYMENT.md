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

Fill in `.env`: `DB_*`, `TELEGRAM_BOT_TOKEN`, `GEMINI_API_KEY`. Leave
`QUEUE_CONNECTION=database` and `MEDIA_DISK=local` unless you have S3/R2
credentials — with every source reference-only, almost no bytes are stored.

Keep the server clock on **UTC**. Timestamps are stored in UTC and rendered
per-channel via `media.telegram_caption.display_timezone`; changing the
system timezone does not change what readers see, but it does change what
"today" means to `FreshnessPolicy`.

```bash
php artisan migrate --force
```

## 4. Writable paths

```bash
sudo mkdir -p /var/log/imvnn
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

## 6. Workers (supervisor)

```bash
sudo cp deploy/supervisor/imvnn.conf /etc/supervisord.d/imvnn.ini
# edit `directory` and `user` to match the deploy
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

`stopwaitsecs` in that file is deliberately larger than each queue's longest
job — see the comment at the top of it. Shortening it reintroduces a real
bug: a worker SIGKILLed mid-job leaves the job reserved, Laravel re-runs it
after `retry_after`, and the publishing scheduler ends up with two chains
posting in parallel.

## 7. Scheduler (cron)

```bash
sudo crontab -u imvnn -e
```

```cron
* * * * * cd /var/www/imvnn && php artisan schedule:run >> /dev/null 2>&1
```

This drives `news:fetch` every 30 minutes (`news_sources.fetch_interval_minutes`).
`schedule:work` under supervisor is an equivalent alternative — use one, not
both, or the fetch fires twice.

## 8. Verify

```bash
php artisan schedule:list                     # news:fetch listed, next due
php artisan news:fetch                        # force one now
sudo supervisorctl status                     # all RUNNING
tail -f storage/logs/laravel.log
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
sudo supervisorctl restart imvnn:*
php artisan publishing:start 1
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
- **Cost**: Gemini is called roughly twice per surviving article (analysis +
  caption). Per-call input and output are both capped; there is no
  cumulative budget ceiling, so watch usage via the
  `[gemini-analysis]`/`[gemini-caption]` token lines in the log.
- **Failed jobs** land in `failed_jobs`; inspect with `php artisan
  queue:failed` and replay with `php artisan queue:retry all`.
