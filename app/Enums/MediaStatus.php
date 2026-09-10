<?php

namespace App\Enums;

/**
 * Lifecycle of a media_assets row. Not every asset visits every state —
 * an externally-referenced embed jumps straight from `pending` to `ready`.
 */
enum MediaStatus: string
{
    case Pending = 'pending';           // extracted, not yet validated
    case Rejected = 'rejected';         // failed validation (bad dimensions, disallowed mime, etc.)
    case Duplicate = 'duplicate';       // resolved to an existing media_assets row
    case Downloading = 'downloading';
    case Downloaded = 'downloaded';
    case Processing = 'processing';     // metadata/hash/variant extraction in flight
    case Analyzing = 'analyzing';       // relevance/quality scoring in flight
    case Ready = 'ready';               // usable for selection/publishing
    case Published = 'published';       // used in at least one Telegram post
    case Failed = 'failed';             // terminal failure after retries exhausted

    public function isTerminalFailure(): bool
    {
        return in_array($this, [self::Rejected, self::Failed], true);
    }

    public function isUsable(): bool
    {
        return in_array($this, [self::Ready, self::Published], true);
    }
}
