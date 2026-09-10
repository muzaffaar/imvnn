<?php

namespace App\Enums;

enum MediaVariantType: string
{
    case Original = 'original';
    case Optimized = 'optimized';     // web-quality, resized to a sane max dimension
    case Thumbnail = 'thumbnail';     // small square/crop for previews and grids
    case Telegram = 'telegram';       // dimensions/size/format tuned to Telegram limits
    case Preview = 'preview';         // low-res placeholder (e.g. blurred LQIP)
    case Compressed = 'compressed';   // same dimensions, aggressively compressed
}
