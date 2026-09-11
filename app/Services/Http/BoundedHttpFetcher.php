<?php

namespace App\Services\Http;

use App\Support\Observability\PipelineLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared HTTP fetch primitives, used by both the media pipeline (image/video
 * downloads and probes) and the news-fetching pipeline (RSS feeds, article
 * pages). Every caller must pass an explicit byte cap — there is no "just
 * download it and see" path.
 */
class BoundedHttpFetcher
{
    public function __construct(private readonly Client $client) {}

    /** @return array{content_length: ?int, content_type: ?string}|null null if HEAD isn't supported/reachable */
    public function head(string $url, ?int $timeoutSeconds = null, ?int $connectTimeoutSeconds = null, array $requestHeaders = []): ?array
    {
        try {
            $response = $this->client->head($url, [
                'timeout' => $timeoutSeconds ?? config('media.limits.download_timeout_seconds'),
                'connect_timeout' => $connectTimeoutSeconds ?? config('media.limits.download_connect_timeout_seconds'),
                'allow_redirects' => true,
                'headers' => $this->headers($requestHeaders),
            ]);
        } catch (GuzzleException $e) {
            PipelineLogger::exception('http.head_failed', $e, [
                'url' => PipelineLogger::url($url),
            ], 'debug');

            return null;
        }

        return $this->extractMeta($response);
    }

    /** @throws BoundedHttpFetchException */
    public function downloadToMemory(string $url, int $maxBytes, ?int $timeoutSeconds = null, ?int $connectTimeoutSeconds = null, array $requestHeaders = []): string
    {
        $response = $this->get($url, $timeoutSeconds, $connectTimeoutSeconds, requestHeaders: $requestHeaders);
        $body = $response->getBody();
        $contents = '';

        while (! $body->eof()) {
            $contents .= $body->read(8192);
            if (strlen($contents) > $maxBytes) {
                $this->throwLimitExceeded($url, $maxBytes);
            }
        }

        if ($contents === '') {
            $this->throwEmptyResponse($url);
        }

        return $contents;
    }

    /**
     * Download a response only when the origin says it changed. This is used
     * for frequently polled feeds: HTTP 304 transfers no XML and avoids
     * parsing an unchanged source.
     *
     * @throws BoundedHttpFetchException
     */
    public function downloadToMemoryConditionally(
        string $url,
        int $maxBytes,
        ?int $timeoutSeconds = null,
        ?int $connectTimeoutSeconds = null,
        ?string $etag = null,
        ?string $lastModified = null,
        array $requestHeaders = [],
    ): ConditionalDownload {
        $headers = array_filter([
            'If-None-Match' => $etag,
            'If-Modified-Since' => $lastModified,
        ], fn (?string $value) => $value !== null && $value !== '');

        $response = $this->get($url, $timeoutSeconds, $connectTimeoutSeconds, $headers, $requestHeaders);

        if ($response->getStatusCode() === 304) {
            return new ConditionalDownload(
                body: null,
                etag: $response->getHeaderLine('ETag') ?: $etag,
                lastModified: $response->getHeaderLine('Last-Modified') ?: $lastModified,
            );
        }

        $body = $response->getBody();
        $contents = '';

        while (! $body->eof()) {
            $contents .= $body->read(8192);
            if (strlen($contents) > $maxBytes) {
                $this->throwLimitExceeded($url, $maxBytes);
            }
        }

        if ($contents === '') {
            $this->throwEmptyResponse($url);
        }

        return new ConditionalDownload(
            body: $contents,
            etag: $response->getHeaderLine('ETag') ?: null,
            lastModified: $response->getHeaderLine('Last-Modified') ?: null,
        );
    }

    /**
     * Fetches only the first $bytes of a resource via a Range request, for
     * reading a file header without transferring (or storing) the file —
     * see ImageDimensionProbe. Servers that ignore Range simply return the
     * whole body, so the read is capped either way.
     *
     * @return array{body: string, total_size: ?int}
     *
     * @throws BoundedHttpFetchException
     */
    public function downloadRange(string $url, int $bytes, ?int $timeoutSeconds = null, array $requestHeaders = []): array
    {
        try {
            $response = $this->client->get($url, [
                'timeout' => $timeoutSeconds ?? config('media.limits.download_timeout_seconds'),
                'connect_timeout' => config('media.limits.download_connect_timeout_seconds'),
                'allow_redirects' => true,
                'stream' => true,
                'headers' => $this->headers($requestHeaders, ['Range' => 'bytes=0-'.($bytes - 1)]),
            ]);
        } catch (GuzzleException $e) {
            PipelineLogger::exception('http.range_request_failed', $e, [
                'url' => PipelineLogger::url($url),
            ], 'warning');

            throw new BoundedHttpFetchException(
                'HTTP range request failed for '.PipelineLogger::url($url).'. Reason: '.PipelineLogger::exceptionMessage($e),
                statusCode: $this->statusCode($e),
                url: PipelineLogger::url($url),
            );
        }

        $body = $response->getBody();
        $contents = '';

        while (! $body->eof() && strlen($contents) < $bytes) {
            $contents .= $body->read(8192);
        }

        return [
            'body' => $contents,
            'total_size' => $this->totalSizeFrom($response),
        ];
    }

    /** Prefers Content-Range's total (the real file size) over the partial Content-Length. */
    private function totalSizeFrom(ResponseInterface $response): ?int
    {
        $contentRange = $response->getHeaderLine('Content-Range');

        if ($contentRange !== '' && preg_match('#/(\d+)$#', $contentRange, $match)) {
            return (int) $match[1];
        }

        $length = $response->getHeaderLine('Content-Length');

        return $length !== '' && $response->getStatusCode() === 200 ? (int) $length : null;
    }

    /** @throws BoundedHttpFetchException */
    public function downloadToFile(string $url, string $destinationPath, int $maxBytes, array $requestHeaders = []): void
    {
        $response = $this->get($url, requestHeaders: $requestHeaders);
        $body = $response->getBody();

        $handle = fopen($destinationPath, 'wb');
        if (! $handle) {
            throw new BoundedHttpFetchException("Could not open {$destinationPath} for writing");
        }

        $written = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(65536);
                $written += strlen($chunk);

                if ($written > $maxBytes) {
                    $this->throwLimitExceeded($url, $maxBytes);
                }

                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }

        if ($written === 0) {
            @unlink($destinationPath);
            $this->throwEmptyResponse($url);
        }
    }

    /**
     * @param  array<string, string>  $additionalHeaders  Headers required by the request type, such as conditional validators.
     * @param  array<string, string>  $requestHeaders  Source-specific headers, which override defaults but not required headers.
     */
    private function get(string $url, ?int $timeoutSeconds = null, ?int $connectTimeoutSeconds = null, array $additionalHeaders = [], array $requestHeaders = []): ResponseInterface
    {
        try {
            return $this->client->get($url, [
                'timeout' => $timeoutSeconds ?? config('media.limits.download_timeout_seconds'),
                'connect_timeout' => $connectTimeoutSeconds ?? config('media.limits.download_connect_timeout_seconds'),
                'allow_redirects' => true,
                'stream' => true,
                'headers' => $this->headers($requestHeaders, $additionalHeaders),
            ]);
        } catch (GuzzleException $e) {
            $statusCode = $this->statusCode($e);
            PipelineLogger::exception('http.request_failed', $e, [
                'method' => 'GET',
                'url' => PipelineLogger::url($url),
                'http_status' => $statusCode,
            ], 'warning');

            throw new BoundedHttpFetchException(
                'HTTP GET request failed for '.PipelineLogger::url($url).'. Reason: '.PipelineLogger::exceptionMessage($e),
                statusCode: $statusCode,
                url: PipelineLogger::url($url),
            );
        }
    }

    /**
     * Returns validated source-level request headers from `sources.fetch_options`.
     * Only headers are configurable here; callers cannot disable TLS checks,
     * change timeouts, alter redirect policy, or bypass byte limits.
     *
     * @param  array<string, mixed>  $fetchOptions
     * @return array<string, string>
     */
    public function headersFromFetchOptions(array $fetchOptions): array
    {
        $configured = $fetchOptions['headers'] ?? [];

        if (! is_array($configured)) {
            throw new \InvalidArgumentException('sources.fetch_options.headers must be an object of HTTP header names and values.');
        }

        $headers = [];
        foreach ($configured as $name => $value) {
            if (! is_string($name) || ! is_string($value)
                || $name === '' || preg_match('/[\r\n:]/', $name)
                || preg_match('/[\r\n]/', $value)) {
                throw new \InvalidArgumentException('sources.fetch_options.headers contains an invalid HTTP header.');
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /** @throws BoundedHttpFetchException */
    private function throwLimitExceeded(string $url, int $maxBytes): never
    {
        PipelineLogger::warning('http.response_too_large', [
            'url' => PipelineLogger::url($url),
            'max_bytes' => $maxBytes,
        ]);

        throw new BoundedHttpFetchException("Response exceeded {$maxBytes} byte limit while downloading ".PipelineLogger::url($url), url: PipelineLogger::url($url));
    }

    /** @throws BoundedHttpFetchException */
    private function throwEmptyResponse(string $url): never
    {
        PipelineLogger::warning('http.empty_response', ['url' => PipelineLogger::url($url)]);

        throw new BoundedHttpFetchException('Empty response body downloading '.PipelineLogger::url($url), url: PipelineLogger::url($url));
    }

    /**
     * Merges defaults, source-specific overrides, and request-required headers
     * case-insensitively. Conditional validators and Range are applied last
     * so a source configuration cannot accidentally disable bounded behavior.
     *
     * @param  array<string, string>  $requestHeaders
     * @param  array<string, string>  $requiredHeaders
     * @return array<string, string>
     */
    private function headers(array $requestHeaders = [], array $requiredHeaders = []): array
    {
        $headers = [
            'User-Agent' => config('media.limits.user_agent'),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];

        foreach ([$requestHeaders, $requiredHeaders] as $overrides) {
            foreach ($overrides as $name => $value) {
                foreach ($headers as $existingName => $_) {
                    if (strcasecmp($existingName, $name) === 0) {
                        unset($headers[$existingName]);
                    }
                }
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function statusCode(GuzzleException $exception): ?int
    {
        if (! method_exists($exception, 'getResponse')) {
            return null;
        }

        $response = $exception->getResponse();

        return $response?->getStatusCode();
    }

    /** @return array{content_length: ?int, content_type: ?string} */
    private function extractMeta(ResponseInterface $response): array
    {
        $length = $response->getHeaderLine('Content-Length');
        $type = $response->getHeaderLine('Content-Type');

        return [
            'content_length' => $length !== '' ? (int) $length : null,
            'content_type' => $type !== '' ? strtok($type, ';') : null,
        ];
    }
}
