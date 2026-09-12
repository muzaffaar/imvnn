<?php

namespace App\Support\Time;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The timezone whose wall clock is physically written in the database's
 * `timestamp without time zone` columns — and the single place that answers
 * the question.
 *
 * PostgreSQL stores those columns as a bare reading with no offset, and
 * Eloquent neither converts on the way in nor labels on the way out: it
 * formats a DateTimeInterface as-is when binding, and interprets a raw
 * reading in PHP's default timezone when hydrating. So the zone the columns
 * are written in is a fact about the data, not about the code, and every
 * layer that touches a timestamp has to be told the same fact.
 *
 * Splitting that fact across several config keys is what broke publishing on
 * 12 September 2026: FreshnessPolicy was pointed at a key set to UTC while
 * the rows themselves held Asia/Tashkent readings, so the day window slid
 * five hours and the scheduler queued yesterday's articles while skipping
 * today's. One key, read from here, is the guard against a repeat.
 *
 * The default is `app.timezone`, which is what the columns hold today (see
 * the 2026_09_11_130000 shift migration). Overriding it is only correct
 * alongside rewriting every stored value — the reading in the column does
 * not change meaning because a config key did.
 */
final class StorageTimezone
{
    public static function zone(): string
    {
        $configured = (string) config('app.storage_timezone', '');

        return $configured !== '' ? $configured : (string) config('app.timezone', 'UTC');
    }

    /** Now, as the wall clock the database stores. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone());
    }

    /** The same instant, expressed as the wall clock the database stores. */
    public static function express(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::zone());
    }

    /**
     * A raw reading from a `timestamp without time zone` column, turned back
     * into the instant it records. A value that carries its own offset — some
     * drivers and some assignments do — keeps that offset rather than being
     * reinterpreted, since it already names an instant.
     */
    public static function interpret(string $reading): CarbonImmutable
    {
        return CarbonImmutable::parse($reading, self::zone())->setTimezone(self::zone());
    }

    /**
     * Anything a timestamp attribute can hold — a date object, a raw column
     * reading, a unix timestamp, null — as the instant it means, expressed in
     * the storage zone. The one conversion both directions share, so a value
     * cannot mean one thing on the way in and another on the way out.
     */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return self::express($value);
        }

        // A unix timestamp names an instant outright; only a wall-clock
        // reading needs the storage zone to be interpretable at all.
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return CarbonImmutable::createFromTimestamp((int) $value, self::zone());
        }

        return self::interpret((string) $value);
    }
}
