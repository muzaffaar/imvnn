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
cp .env.example .env   # then fill in DB_*, TELEGRAM_BOT_TOKEN, GEMINI_API_KEY
php artisan key:generate
php artisan migrate

php artisan news-sources:sync        # config/news_sources.php -> sources table
php artisan telegram:channel @your_channel   # verifies the bot is an admin there
php artisan publishing:start 1       # id printed by the previous command
```

Then start the workers (see [Running the pipeline](#running-the-pipeline)).

Requires PostgreSQL (for `jsonb` columns), and `ffmpeg`/`ffprobe` on `PATH`
(or set `FFMPEG_BINARY`/`FFPROBE_BINARY`) for video processing.

> **`migrate:fresh` erases publish history.** `telegram_published_at` is
> what stops an article being posted twice, so anything already posted
> *today* becomes eligible again and will repost. Older articles are
> unaffected — the freshness filter blocks them regardless.

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

**Optional: set `GEMINI_API_KEY` in `.env`** to have Gemini parse each
article and judge its AI-relevance (instead of the free heuristic
parser + keyword filter) — see
[`docs/NEWS_FETCHING.md`](docs/NEWS_FETCHING.md#ai-relevance-filtering-and-analysis)
for cost limits and fallback behavior. Leave it empty to skip Gemini entirely.

## Running the pipeline

News fetching (`FetchNewsSourceJob` → `ProcessNewsCandidateJob`) creates a
`NewsItem` and automatically dispatches `App\Jobs\Media\ExtractMediaJob` for
it — everything from there on is the media pipeline, queued automatically;
see the pipeline diagram in `docs/MEDIA_ARCHITECTURE.md`. Run workers per
queue pool, e.g.:

```
php artisan queue:work --queue=news-fetch,news-parse,media-extraction,media-download,image-analysis,media-optimization,media-selection
php artisan queue:work --queue=video-processing          # isolated, keep separate
php artisan queue:work --queue=telegram-publishing
php artisan schedule:work                                 # drives the periodic news:fetch
```

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
article, waits a random 15–120 minutes (configurable per channel via
`rules.min_publish_interval_minutes`/`max_publish_interval_minutes`), then
checks again — forever, even with several good articles backlogged. See
`docs/MEDIA_ARCHITECTURE.md` "Publishing scheduler".

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
