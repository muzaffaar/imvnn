<?php

namespace App\Enums;

/**
 * How the bytes (if any) are actually served, independent of MediaStatus.
 */
enum MediaProvider: string
{
    case External = 'external';       // we only ever link to the original URL (e.g. YouTube, most video)
    case Downloaded = 'downloaded';   // we copied the bytes into our own object storage
    case Embed = 'embed';             // rendered via platform embed/oEmbed, no file at all
}
