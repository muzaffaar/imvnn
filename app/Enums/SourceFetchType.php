<?php

namespace App\Enums;

enum SourceFetchType: string
{
    case Rss = 'rss';
    case HtmlCrawl = 'html_crawl';
}
