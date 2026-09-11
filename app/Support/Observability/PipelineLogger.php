<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Structured, secret-safe logs for the asynchronous news pipeline.
 *
 * Keep article text, prompts, HTTP request bodies, API keys, and Telegram
 * tokens out of application logs. Callers should pass identifiers and counts
 * rather than raw payloads.
 */
final class PipelineLogger
{
    /** @param array<string, mixed> $context */
    public static function debug(string $event, array $context = []): void
    {
        Log::debug("pipeline.{$event}", self::sanitizeContext($context));
    }

    /** @param array<string, mixed> $context */
    public static function info(string $event, array $context = []): void
    {
        Log::info("pipeline.{$event}", self::sanitizeContext($context));
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $event, array $context = []): void
    {
        Log::warning("pipeline.{$event}", self::sanitizeContext($context));
    }

    /** @param array<string, mixed> $context */
    public static function error(string $event, array $context = []): void
    {
        Log::error("pipeline.{$event}", self::sanitizeContext($context));
    }

    /** @param array<string, mixed> $context */
    public static function exception(string $event, Throwable $exception, array $context = [], string $level = 'error'): void
    {
        $context += [
            'exception_class' => $exception::class,
            'reason' => self::exceptionMessage($exception),
        ];

        match ($level) {
            'debug' => self::debug($event, $context),
            'info' => self::info($event, $context),
            'warning' => self::warning($event, $context),
            default => self::error($event, $context),
        };
    }

    /** Removes credentials, query strings, and fragments while retaining a useful origin/path. */
    public static function url(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return self::sanitize($url);
        }

        return "{$parts['scheme']}://{$parts['host']}".($parts['path'] ?? '');
    }

    public static function exceptionMessage(Throwable $exception): string
    {
        return self::sanitize($exception->getMessage());
    }

    public static function sanitize(string $value): string
    {
        foreach ([config('services.ai.api_key'), config('services.telegram.bot_token')] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $value = str_replace($secret, '[redacted]', $value);
            }
        }

        $value = preg_replace('/([?&](?:api[_-]?key|x[_-]?api[_-]?key|x-goog-api-key|key|token|access[_-]?token|authorization|client[_-]?secret|password|signature|sig)=)[^&\s]+/i', '$1[redacted]', $value) ?? $value;
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redacted]', $value) ?? $value;
        $value = preg_replace('#(https?://api\.telegram\.org/bot)[^/\s]+#i', '$1[redacted]', $value) ?? $value;

        return $value;
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private static function sanitizeContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = self::sanitize($value);
            } elseif (is_array($value)) {
                $context[$key] = self::sanitizeContext($value);
            }
        }

        return $context;
    }
}
