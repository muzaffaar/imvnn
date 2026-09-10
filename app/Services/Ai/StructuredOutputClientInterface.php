<?php

namespace App\Services\Ai;

interface StructuredOutputClientInterface
{
    /**
     * Sends a prompt to the configured model provider and returns its JSON
     * object response. Provider-specific request/response formats stay behind
     * this boundary so article analysis and caption generation are portable.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function generate(
        string $operation,
        string $prompt,
        array $schema,
        int $maxOutputTokens,
        float $temperature,
        int $timeoutSeconds,
    ): array;
}
