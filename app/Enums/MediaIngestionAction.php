<?php

namespace App\Enums;

enum MediaIngestionAction: string
{
    case Ignore = 'ignore';         // discard, doesn't even become a media_assets row
    case Reference = 'reference';   // create a media_assets row, but only ever link to original_url
    case Download = 'download';     // create a media_assets row and queue DownloadMediaJob
}
