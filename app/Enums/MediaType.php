<?php

namespace App\Enums;

/**
 * Extensible media type taxonomy. Adding a new type never requires a migration —
 * it's a plain string column — only a new case here plus handling in the relevant
 * extractor/scorer/variant generator.
 */
enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Gif = 'gif';
    case Embed = 'embed';
    case Thumbnail = 'thumbnail';
    case Infographic = 'infographic';
    case Chart = 'chart';

    public function isVisual(): bool
    {
        return in_array($this, [self::Image, self::Gif, self::Infographic, self::Chart, self::Thumbnail], true);
    }

    public function isDownloadableByDefault(): bool
    {
        return in_array($this, [self::Image, self::Gif, self::Infographic, self::Chart, self::Document], true);
    }
}
