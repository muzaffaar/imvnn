<?php

namespace App\Services\Media\Extraction;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

trait ResolvesUrls
{
    /** Resolve a possibly-relative URL against the article's base URL. */
    protected function resolveUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }
        try {
            $resolved = UriResolver::resolve(
                new Uri($baseUrl),
                new Uri($url),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
        if (! in_array($resolved->getScheme(), ['http', 'https'], true) || $resolved->getHost() === '') {
            return null;
        }

        return (string) $resolved;
    }
}
