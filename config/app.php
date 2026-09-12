<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions.
    |
    | It is deliberately the audience's timezone rather than UTC: this
    | application's whole job is "today's news, on the day the readers are
    | living in", so `now()` and every date it prints read as the day the
    | channel's readers are actually in.
    |
    | Safe here specifically because Asia/Tashkent is a fixed UTC+05:00 with no
    | daylight saving (abolished in 1996), so local time never repeats or skips
    | an hour. Do NOT point this at a DST zone without revisiting
    | App\Services\News\FreshnessPolicy and App\Services\News\PublishDateParser.
    |
    | Keep it in step with NEWS_DAY_TIMEZONE, which decides which calendar day
    | an article belongs to.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Database Storage Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone whose wall clock is physically written in the database's
    | `timestamp without time zone` columns. It is a fact about the DATA, not a
    | preference: those columns carry no offset, so a stored reading means
    | nothing until something names the zone it was written in.
    |
    | Every layer that touches a timestamp reads it from here, through
    | App\Support\Time\StorageTimezone — hydration (App\Casts\StoredDateTime),
    | the publish-date parser, and the freshness window's SQL boundaries. They
    | must agree, and the only way to guarantee that is for there to be one
    | answer. Publishing broke on 12 September 2026 because there were two.
    |
    | It defaults to `app.timezone`, which is what the columns hold today (see
    | the 2026_09_11_130000 shift migration). Changing it does NOT reinterpret
    | the existing rows into a new zone — it asserts what they already contain,
    | so a change is only correct together with a migration that rewrites every
    | stored value by the offset between the two zones.
    |
    */

    'storage_timezone' => env('DB_STORAGE_TIMEZONE', env('APP_TIMEZONE', 'UTC')),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
