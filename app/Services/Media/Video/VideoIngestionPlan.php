<?php

namespace App\Services\Media\Video;

final class VideoIngestionPlan
{
    public function __construct(
        public readonly bool $shouldDownload,
        public readonly bool $shouldFetchThumbnail,
        public readonly bool $shouldConsiderTranscode,
        public readonly string $reason,
    ) {}
}
