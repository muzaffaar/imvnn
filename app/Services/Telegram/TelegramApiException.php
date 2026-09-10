<?php

namespace App\Services\Telegram;

class TelegramApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $deliveryUnknown = false,
        public readonly ?int $retryAfter = null,
        public readonly bool $mediaRejected = false,
    ) {
        parent::__construct($message);
    }
}
