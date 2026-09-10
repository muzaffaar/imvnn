<?php

namespace App\Services\Media\Extraction;

trait ResolvesUrls
{
    /** Resolve a possibly-relative URL against the article's base URL. */
    protected function resolveUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return "{$scheme}:{$url}";
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $base = parse_url($baseUrl);
        if (! $base || empty($base['host'])) {
            return null;
        }

        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$port}{$url}";
        }

        $basePath = isset($base['path']) ? rtrim(dirname($base['path']), '/') : '';

        return "{$scheme}://{$host}{$port}{$basePath}/{$url}";
    }
}
