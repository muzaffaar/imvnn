<?php

namespace App\Services\Media\Deduplication;

class UrlNormalizer
{
    private const TRACKING_PARAM_PREFIXES = ['utm_', 'fbclid', 'gclid', 'ref', 'ito', 'icid', 'cmpid'];

    /** Strip scheme, tracking params, fragment, and trailing slash so trivially-different URLs still match. */
    public function normalize(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! $parts || empty($parts['host'])) {
            return rtrim($url, '/');
        }

        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/') ?: '/';

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (array_keys($query) as $key) {
                foreach (self::TRACKING_PARAM_PREFIXES as $prefix) {
                    if (str_starts_with(strtolower($key), $prefix)) {
                        unset($query[$key]);
                        break;
                    }
                }
            }
            ksort($query);
        }

        $queryString = $query ? '?'.http_build_query($query) : '';

        return "{$host}{$path}{$queryString}";
    }
}
