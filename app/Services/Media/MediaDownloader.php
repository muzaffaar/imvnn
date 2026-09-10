<?php

namespace App\Services\Media;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared HTTP fetch primitives for both image downloads (buffered) and video
 * probing/downloads (streamed to disk, since videos can be hundreds of MB).
 * Every caller must pass an explicit byte cap — there is no "just download it
 * and see" path, per the size-limit rules in config('media.limits').
 */
class MediaDownloader
{
    public function __construct(private readonly Client $client) {}

    /** @return array{content_length: ?int, content_type: ?string}|null null if HEAD isn't supported/reachable */
    public function head(string $url): ?array
    {
        try {
            $response = $this->client->head($url, [
                'timeout' => config('media.limits.download_timeout_seconds'),
                'connect_timeout' => config('media.limits.download_connect_timeout_seconds'),
                'allow_redirects' => true,
            ]);
        } catch (GuzzleException) {
            return null;
        }

        return $this->extractMeta($response);
    }

    /** @throws MediaDownloadException */
    public function downloadToMemory(string $url, int $maxBytes): string
    {
        $response = $this->get($url);
        $body = $response->getBody();
        $contents = '';

        while (! $body->eof()) {
            $contents .= $body->read(8192);
            if (strlen($contents) > $maxBytes) {
                throw new MediaDownloadException("Response exceeded {$maxBytes} byte limit while downloading {$url}");
            }
        }

        if ($contents === '') {
            throw new MediaDownloadException("Empty response body downloading {$url}");
        }

        return $contents;
    }

    /** @throws MediaDownloadException */
    public function downloadToFile(string $url, string $destinationPath, int $maxBytes): void
    {
        $response = $this->get($url);
        $body = $response->getBody();

        $handle = fopen($destinationPath, 'wb');
        if (! $handle) {
            throw new MediaDownloadException("Could not open {$destinationPath} for writing");
        }

        $written = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(65536);
                $written += strlen($chunk);

                if ($written > $maxBytes) {
                    throw new MediaDownloadException("Download exceeded {$maxBytes} byte limit: {$url}");
                }

                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }

        if ($written === 0) {
            @unlink($destinationPath);
            throw new MediaDownloadException("Empty response body downloading {$url}");
        }
    }

    private function get(string $url): ResponseInterface
    {
        try {
            return $this->client->get($url, [
                'timeout' => config('media.limits.download_timeout_seconds'),
                'connect_timeout' => config('media.limits.download_connect_timeout_seconds'),
                'allow_redirects' => true,
                'stream' => true,
                'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; NewsMediaBot/1.0)'],
            ]);
        } catch (GuzzleException $e) {
            throw new MediaDownloadException("Failed to fetch {$url}: {$e->getMessage()}", previous: $e);
        }
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
