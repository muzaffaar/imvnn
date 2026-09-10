<?php

namespace App\Services\Http;

/**
 * A bounded HTTP download together with validators that can be saved and sent
 * on the next poll. A null body means the origin returned HTTP 304.
 */
final readonly class ConditionalDownload
{
    public function __construct(
        public ?string $body,
        public ?string $etag,
        public ?string $lastModified,
    ) {}

    public function wasNotModified(): bool
    {
        return $this->body === null;
    }
}
