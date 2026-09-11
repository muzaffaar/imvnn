<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rewrites every stored timestamp from UTC into the application timezone.
 *
 * `config('app.timezone')` moved from UTC to Asia/Tashkent, and that changes how
 * existing rows are *read*, not just how new ones are written: Eloquent binds and
 * hydrates a timestamp by formatting it as-is, without converting the zone. Left
 * alone, a row written as 21:58 UTC would be read back as 21:58 Tashkent — five
 * hours later than the instant it recorded. For `news_items.published_at` that
 * is enough to move an article between calendar days, which is exactly what the
 * freshness policy turns on.
 *
 * Safe to run once and only once, which is why it is a migration rather than a
 * command. Re-running would shift the data a second time.
 *
 * The offset is derived from the configured zone rather than hard-coded, but it
 * is applied as a single constant shift, which is correct only for a zone with
 * no daylight saving. Asia/Tashkent has been a fixed UTC+05:00 since 1996. A DST
 * zone would need each row converted against its own date.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'event_media' => ['created_at', 'updated_at'],
        'events' => ['first_seen_at', 'created_at', 'updated_at'],
        'failed_jobs' => ['failed_at'],
        'media_assets' => ['downloaded_at', 'processed_at', 'created_at', 'updated_at'],
        'media_metadata' => ['created_at', 'updated_at'],
        'media_processing_logs' => ['created_at', 'updated_at'],
        'media_variants' => ['created_at', 'updated_at'],
        'news_items' => [
            'published_at', 'created_at', 'updated_at', 'media_analysis_completed_at',
            'publish_queued_at', 'telegram_published_at', 'telegram_publish_started_at',
        ],
        'news_media' => ['created_at', 'updated_at'],
        'sources' => ['created_at', 'updated_at', 'last_fetched_at'],
        'telegram_channels' => ['created_at', 'updated_at'],
        'users' => ['email_verified_at', 'created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->shift($this->offsetSeconds());
    }

    public function down(): void
    {
        $this->shift(-$this->offsetSeconds());
    }

    /**
     * Seconds to add to a UTC reading to express the same instant as local wall
     * clock in the application timezone.
     */
    private function offsetSeconds(): int
    {
        return CarbonImmutable::now('UTC')
            ->setTimezone((string) config('app.timezone', 'UTC'))
            ->getOffset();
    }

    private function shift(int $seconds): void
    {
        if ($seconds === 0) {
            return; // storage is UTC; nothing to rewrite
        }

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)->whereNotNull($column)->update([
                    $column => DB::raw($this->shiftExpression($column, $seconds)),
                ]);
            }
        }
    }

    /**
     * Date arithmetic is not portable, and this has to run on the SQLite used by
     * the test suite as well as the PostgreSQL used in production.
     */
    private function shiftExpression(string $column, int $seconds): string
    {
        $signed = ($seconds > 0 ? '+' : '-').abs($seconds);

        return match (DB::connection()->getDriverName()) {
            'sqlite' => "datetime({$column}, '{$signed} seconds')",
            'mysql', 'mariadb' => "date_add({$column}, interval {$seconds} second)",
            'sqlsrv' => "dateadd(second, {$seconds}, {$column})",
            default => "{$column} + interval '{$signed} seconds'",
        };
    }
};
