<?php

namespace App\Enums;

enum PostMediaType: string
{
    case None = 'none';
    case SingleImage = 'single_image';
    case MediaGroup = 'media_group';       // Telegram album (2-10 photos)
    case Video = 'video';                   // we can upload/send the video natively
    case VideoThumbnailFallback = 'video_thumbnail_fallback'; // video exists but isn't sendable; post its thumbnail + link instead
}
