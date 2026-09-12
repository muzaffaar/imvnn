<?php

namespace App\Casts;

use App\Support\Time\StorageTimezone;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A timestamp column read and written in the timezone the database physically
 * stores, whatever PHP's default timezone happens to be.
 *
 * Laravel's built-in `datetime` cast has no notion of a storage timezone. It
 * hydrates a bare `2026-09-11 23:24:38` by parsing it in PHP's default zone
 * (`app.timezone`) and writes a Carbon back by formatting it as-is, without
 * converting. Both halves are only correct while `app.timezone` happens to
 * equal the zone the column was written in — an invariant nothing enforces
 * and nothing announces when it breaks. It breaks silently and by a whole
 * offset, which for `news_items.published_at` is enough to move an article
 * between calendar days, which is precisely what the freshness policy turns
 * on.
 *
 * This cast makes the dependency explicit and one-directional: the instant is
 * derived from the stored reading plus StorageTimezone, and written back by
 * converting to that same zone. `app.timezone` then only decides how a
 * timestamp is *displayed*, which is all it should ever have decided.
 *
 * Returns an immutable Carbon so a date read from a model cannot be mutated
 * in place by a caller that meant to derive a new one.
 *
 * @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|string|int|null>
 */
class StoredDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return StorageTimezone::parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return StorageTimezone::parse($value)?->format($model->getDateFormat());
    }
}
