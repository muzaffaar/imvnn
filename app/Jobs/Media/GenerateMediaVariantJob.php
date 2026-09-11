<?php

namespace App\Jobs\Media;

use App\Enums\MediaVariantType;
use App\Models\MediaAsset;
use App\Services\Media\Variants\MediaVariantService;
use App\Support\Observability\PipelineLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * On-demand variant generation for callers outside the main selection flow
 * (SelectMediaForPublishingJob generates its chosen variant inline since it
 * needs the result immediately) — e.g. an admin backfill or a manual retry.
 */
class GenerateMediaVariantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(
        public readonly string $mediaAssetId,
        public readonly MediaVariantType $variantType,
    ) {
        $this->onQueue(config('media.queues.optimization'));
    }

    public function handle(MediaVariantService $variantService): void
    {
        $asset = MediaAsset::with('variants')->findOrFail($this->mediaAssetId);
        $variantService->ensure($asset, $this->variantType);

        PipelineLogger::info('media.variant_completed', [
            'media_asset_id' => $asset->id,
            'variant_type' => $this->variantType->value,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        PipelineLogger::exception('media.variant_failed', $exception, [
            'media_asset_id' => $this->mediaAssetId,
            'variant_type' => $this->variantType->value,
        ]);
    }
}
