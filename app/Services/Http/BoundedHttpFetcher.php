<?php

namespace App\Services\Http;

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
    public function head(string $url, ?int $timeoutSeconds = null, ?int $connectTimeoutSeconds = null): ?array
    {
        try {
            $response = $this->client->head($url, [
                'timeout' => $timeoutSeconds ?? config('media.limits.download_timeout_seconds'),
                'connect_timeout' => $connectTimeoutSeconds ?? config('media.limits.download_connect_timeout_seconds'),
                'allow_redirects' => true,
                'headers' => $this->browserHeaders(),
            ]);
        } catch (GuzzleException) {
            return null;
        }

        return $this->extractMeta($response);
    }

    /** @throws BoundedHttpFetchException */
    public function downloadToMemory(string $url, int $maxBytes, ?int $timeoutSeconds = null, ?int $connectTimeoutSeconds = null): string
    {
        $response = $this->get($url, $timeoutSeconds, $connectTimeoutSeconds);
        $body = $response->getBody();
        $contents = '';

        while (! $body->eof()) {
            $contents .= $body->read(8192);
            if (strlen($contents) > $maxBytes) {
                throw new BoundedHttpFetchException("Response exceeded {$maxBytes} byte limit while downloading {$url}");
            }
        }

        if ($contents === '') {
            throw new BoundedHttpFetchException("Empty response body downloading {$url}");
        }

        return $contents;
    }

    /** @throws BoundedHttpFetchException */
    public function downloadToFile(string $url, string $destinationPath, int $maxBytes): void
    {
        $response = $this->get($url);
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
                    throw new BoundedHttpFetchException("Download exceeded {$maxBytes} byte limit: {$url}");
                }

                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }

        if ($written === 0) {
            @unlink($destinationPath);
            throw new BoundedHttpFetchException("Empty response body downloading {$url}");
        }
    }

    private function get(string $url, ?int $timeoutSeconds = null, ?int $connectTimeoutSeconds = null): ResponseInterface
    {
        try {
            return $this->client->get($url, [
                'timeout' => $timeoutSeconds ?? config('media.limits.download_timeout_seconds'),
                'connect_timeout' => $connectTimeoutSeconds ?? config('media.limits.download_connect_timeout_seconds'),
                'allow_redirects' => true,
                'stream' => true,
                'headers' => $this->browserHeaders(),
            ]);
        } catch (GuzzleException $e) {
            throw new BoundedHttpFetchException("Failed to fetch {$url}: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * A self-identifying bot User-Agent (e.g. "NewsMediaBot/1.0") gets flat
     * out 403'd by several real news/blog sites (confirmed against
     * anthropic.com, huggingface.co, and others) even though they have no
     * real anti-bot challenge — they just filter on User-Agent. A realistic
     * browser UA + Accept headers is enough for all of them; none needed a
     * headless browser once this was fixed.
     */
    private function browserHeaders(): array
    {
        return [
            'User-Agent' => config('media.limits.user_agent'),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];
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
