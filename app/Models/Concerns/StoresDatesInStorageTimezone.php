<?php

namespace App\Models\Concerns;

use App\Support\Time\StorageTimezone;

/**
 * Writes every date this model sends to the database as the wall clock the
 * database stores, rather than as whatever zone the value happened to carry.
 *
 * The per-attribute App\Casts\StoredDateTime cast covers the attributes a
 * model declares. This covers the seam underneath it — `fromDateTime()` is
 * what Eloquent itself calls to turn a date into a bound value, including for
 * `created_at`/`updated_at` maintained by the framework — so an attribute
 * cannot be written in the application timezone merely because nobody
 * remembered to declare it.
 */
trait StoresDatesInStorageTimezone
{
    /**
     * @param  mixed  $value
     * @return string|null
     */
    public function fromDateTime($value)
    {
        return StorageTimezone::parse($value)?->format($this->getDateFormat());
    }
}
