<?php

namespace App\Services\Http;

class BoundedHttpFetchException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $url = null,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return $this->statusCode === null
            || in_array($this->statusCode, [408, 425, 429], true)
            || $this->statusCode >= 500;
    }
}
