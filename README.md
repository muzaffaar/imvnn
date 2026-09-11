# IMV NN Channel — Media Pipeline

News media collection/processing pipeline (extraction, deduplication,
quality/relevance scoring, storage, video processing, Telegram publishing)
on a minimal Laravel news-pipeline stub. See
[`docs/MEDIA_ARCHITECTURE.md`](docs/MEDIA_ARCHITECTURE.md) for the full
design rationale, [`docs/NEWS_FETCHING.md`](docs/NEWS_FETCHING.md) for the
source/crawl side, and [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) to run it
on a server.

There is no HTTP surface — everything is queue- and CLI-driven, so no web
server is required.

## Setup

Full sequence from a clean checkout — or from a `migrate:fresh`, which
drops every table including the channel and all publish history:

```
composer install
cp .env.example .env   # then fill in DB_*, TELEGRAM_BOT_TOKEN, AI_API_KEY
php artisan key:generate
php artisan migrate

php artisan news-sources:sync        # config/news_sources.php -> sources table
php artisan telegram:channel @your_channel   # verifies the bot is an admin there
php artisan publishing:start 1       # id printed by the previous command
```

Then start the workers (see [Running the pipeline](#running-the-pipeline)).

Requires PostgreSQL (for `jsonb` columns), and `ffmpeg`/`ffprobe` on `PATH`
(or set `FFMPEG_BINARY`/`FFPROBE_BINARY`) for video processing.

> **`migrate:fresh` erases publish history.** What stops an article being
> posted twice is the `published_posts` ledger, keyed by the article's
> canonical URL per channel — so rebuilding `news_items` alone is now safe,
> but `migrate:fresh` drops the ledger too and anything already posted
> *today* becomes eligible again. Older articles are unaffected: the
> freshness filter blocks them regardless. No database table can survive
> `migrate:fresh`, so treat it as "repost today's news".

## Adding news sources

**Paste your source links into [`config/news_sources.php`](config/news_sources.php)**
(the `sources` array — see the examples commented out there), then run:

```
php artisan news-sources:sync   # upserts config into the sources table, by slug
php artisan news:fetch          # dispatches a fetch job for every active source
```

Each source is either `fetch_type: 'rss'` (a feed URL, polled directly) or
`fetch_type: 'html_crawl'` (a page to scan for article links, each then
fetched and parsed individually). Candidates are matched against
`ai_keywords` in the same config file before a `NewsItem` is ever created —
see `App\Services\News\AiRelevanceFilter`. `news:fetch` is also scheduled
automatically every `fetch_interval_minutes` (see `routes/console.php`), so
once sources are synced and workers are running, fetching happens on its own.

**Optional: configure an AI provider in `.env`** to have it parse each
article and judge its AI-relevance (instead of the free heuristic
parser + keyword filter) — see
[`docs/NEWS_FETCHING.md`](docs/NEWS_FETCHING.md#ai-relevance-filtering-and-analysis)
for cost limits and fallback behavior. Leave it empty to skip AI analysis entirely.

## Running the pipeline

News fetching (`FetchNewsSourceJob` → `ProcessNewsCandidateJob`) creates a
`NewsItem` and automatically dispatches `App\Jobs\Media\ExtractMediaJob` for
it — everything from there on is the media pipeline, queued automatically;
see the pipeline diagram in `docs/MEDIA_ARCHITECTURE.md`. Run workers per
queue pool, e.g.:

```
php artisan queue:work --queue=news-fetch,news-parse,media-extraction,media-download,media-processing,image-analysis,media-optimization,media-selection
php artisan queue:work --queue=video-processing          # isolated, keep separate
php artisan queue:work --queue=telegram-publishing
php artisan schedule:work                                 # drives the periodic news:fetch
```

All four have to be running for the channel to post. The first pool includes
`media-selection`, which is where the publishing scheduler's own chain lives —
without a worker on it the chain stops dead and nothing is published, even
with a full backlog of ready articles and no failed jobs to show for it.
`App\Enums\QueueName::workerPools()` is the source of truth for this list;
`deploy/supervisor/imvnn.conf` runs the same four under Supervisor in
production.

**If you edit any PHP file while these are running, restart them** —
long-running `queue:work`/`schedule:work` processes cache code in memory
and won't pick up changes until restarted.

Create a `TelegramChannel` row (`chat_id`, and `rules` JSON for
`min_quality_score`/`prefer_video`/etc.), then start its publishing
scheduler **once**:

```
php artisan publishing:start {channel_id}
```

From then on it runs itself: it publishes the single best ready-and-unpublished
article, waits, then checks again — forever, even with several good articles
backlogged. See `docs/MEDIA_ARCHITECTURE.md` "Publishing scheduler".

The cadence comes from the channel's `rules`, and the same command sets them,
so a posting rate never has to be edited into the JSON column by hand:

```
php artisan publishing:start 1 --min-interval=5 --max-interval=5 --min-priority=0
```

- `--max-interval` is the baseline wait when nothing else is ready;
  `--min-interval` is the floor it shrinks toward as a backlog builds. **Equal
  values mean a fixed interval and switch the adaptive pacing off**, since there
  is no range left to choose from — give the two some distance apart.

  Between them, the interval is chosen by dividing the time left in today's
  freshness window across the articles still waiting, so the channel speeds up
  as the day runs out. That matters because an article that misses midnight is
  abandoned, not carried over: at a fixed five minutes, a chain that reaches
  23:30 with ten articles waiting posts six and silently loses four. With
  `--min-interval=3 --max-interval=45`:

  | Time | Waiting | Interval chosen |
  |---|---|---|
  | 10:00 | 2 | 45 min |
  | 18:30 | 10 | 30 min |
  | 22:00 | 25 | 4.6 min |
  | 23:30 | 10 | 3 min (floor) |

  Arrival rate needs no separate measurement: more news arriving is exactly what
  makes the backlog grow. If even the floor cannot clear the backlog in time, the
  surplus still expires at midnight and the scheduler logs
  `telegram.scheduler_backlog_will_expire` with the numbers, rather than
  dropping them silently. Set the channel rule `pace_to_end_of_day` to false to
  keep an even cadence instead.
- `--min-priority` (0-1) is the news-priority score an article must reach to
  be published at all. The default, 0.20, holds back routine updates — but an
  article scoring zero is then held back *permanently*, not deferred. Use 0
  for a channel that should publish everything eligible.

Re-running the command is how the cadence is changed: it mints a new chain
token, so any chain already running retires itself on its next pass instead of
posting alongside the new one.

## Timezone

Every timestamp is **stored** in `APP_TIMEZONE`, which is set to
`Asia/Tashkent` rather than UTC so the database reads in the same clock the
audience lives in and the freshness comparison never straddles two zones.
Keep it equal to `NEWS_DAY_TIMEZONE`.

This works because Asia/Tashkent is a fixed UTC+05:00 with no daylight saving.
Pointing `APP_TIMEZONE` at a DST zone needs
[`FreshnessPolicy`](app/Services/News/FreshnessPolicy.php) and
[`PublishDateParser`](app/Services/News/PublishDateParser.php) revisited first,
and the one-off migration that shifted existing rows applies a single constant
offset that a DST zone would make wrong.

## Development mode: catch everything, post often

Four settings in `.env` turn the channel from "today's AI news, paced over
hours" into "anything recent, every few minutes" — useful for seeing the whole
pipeline move before narrowing what it carries:

```
NEWS_TOPIC_FILTER_ENABLED=false   # take any subject, not just AI/ML/Robotics
NEWS_MAX_AGE_HOURS=72             # rolling window instead of "only today"
PIPELINE_LOG_CANDIDATE_SKIPS=true # log why each candidate was dropped
TELEGRAM_CAPTION_FALLBACK_ORIGINAL=true  # post untranslated rather than not at all
```

Why each matters is measured, not guessed — across one live fetch of all 15
sources, 273 candidates became 4 articles: 200 were dropped by only-today and
69 by the keyword filter. With the first two settings above, the same fetch
produced 51. See `docs/NEWS_FETCHING.md` for both gates.

To go back to production behaviour, set `NEWS_TOPIC_FILTER_ENABLED=true`,
clear `NEWS_MAX_AGE_HOURS`, and raise the interval with `publishing:start`.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
