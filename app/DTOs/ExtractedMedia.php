<?php

namespace App\DTOs;

use App\Enums\MediaType;

/**
 * The common shape every MediaExtractor implementation normalizes its findings
 * into, before MediaIngestionPolicy decides what happens to each one.
 */
final class ExtractedMedia
{
    public function __construct(
        public readonly string $url,
        public readonly MediaType $type,
        public readonly string $extractedBy,     // 'og_meta', 'html_img', 'rss_enclosure', 'youtube_adapter', ...
        public readonly ?string $caption = null,
        public readonly ?string $altText = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $durationSeconds = null,
        public readonly ?string $externalProvider = null, // youtube, twitter, cdn, ...
        public readonly ?string $externalId = null,
        public readonly ?string $thumbnailUrl = null,      // for videos/embeds
        public readonly int $position = 0,                  // order of appearance in the source document
        public readonly bool $isFeaturedHint = false,        // e.g. came from og:image / RSS featured enclosure
        public readonly array $extra = [],
    ) {}

    public function withPosition(int $position): self
    {
        return new self(
            $this->url, $this->type, $this->extractedBy, $this->caption, $this->altText,
            $this->width, $this->height, $this->durationSeconds, $this->externalProvider,
            $this->externalId, $this->thumbnailUrl, $position, $this->isFeaturedHint, $this->extra,
        );
    }

    /** Best-effort dedup key before a canonical_url normalization pass is even possible. */
    public function fingerprint(): string
    {
        return $this->externalProvider && $this->externalId
            ? "{$this->externalProvider}:{$this->externalId}"
            : $this->url;
    }
}
