<?php

namespace App\Services\Ai;

interface StructuredOutputClientInterface
{
    /**
     * Sends a prompt to the configured model provider and returns its JSON
     * object response. Provider-specific request/response formats stay behind
     * this boundary so article analysis and caption generation are portable.
     *
     * `$images`, when non-empty, attaches inline image data to a single
     * multimodal call — e.g. `[['mime_type' => 'image/jpeg', 'data' =>
     * base64_encode($bytes)]]` — for the vision-based media relevance pass.
     * Both providers support at most a handful of small images per call; the
     * caller is responsible for keeping bytes small (see
     * media.relevance.max_vision_image_bytes).
     *
     * @param  array<string, mixed>  $schema
     * @param  list<array{mime_type: string, data: string}>  $images
     * @return array<string, mixed>
     */
    public function generate(
        string $operation,
        string $prompt,
        array $schema,
        int $maxOutputTokens,
        float $temperature,
        int $timeoutSeconds,
        array $images = [],
    ): array;
}
