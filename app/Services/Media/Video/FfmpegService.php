<?php

namespace App\Services\Media\Video;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around the ffmpeg/ffprobe binaries. Every call runs in a job on
 * the isolated 'video-processing' queue (see docs/MEDIA_ARCHITECTURE.md) — never in
 * an HTTP request or a default-queue worker, since a single transcode can pin
 * a CPU core for tens of seconds to minutes.
 */
class FfmpegService
{
    /** @return array{width:?int,height:?int,duration_seconds:?int,codec:?string,format:?string}|null */
    public function probe(string $localPath): ?array
    {
        $process = new Process([
            config('media.video.ffprobe_binary'),
            '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,codec_name,duration:format=duration,format_name',
            '-of', 'json',
            $localPath,
        ]);
        $process->setTimeout(config('media.video.process_timeout_seconds'));

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            Log::warning('ffprobe failed', ['path' => $localPath, 'error' => $e->getMessage()]);

            return null;
        }

        $data = json_decode($process->getOutput(), true);
        $stream = $data['streams'][0] ?? [];
        $format = $data['format'] ?? [];

        $duration = $stream['duration'] ?? $format['duration'] ?? null;

        return [
            'width' => isset($stream['width']) ? (int) $stream['width'] : null,
            'height' => isset($stream['height']) ? (int) $stream['height'] : null,
            'duration_seconds' => $duration !== null ? (int) round((float) $duration) : null,
            'codec' => $stream['codec_name'] ?? null,
            'format' => $format['format_name'] ?? null,
        ];
    }

    /** Extracts a single JPEG frame near the start of the video as a thumbnail. */
    public function generateThumbnail(string $localPath, string $outputPath, int $atSeconds = 1): bool
    {
        $process = new Process([
            config('media.video.ffmpeg_binary'),
            '-y',
            '-ss', (string) $atSeconds,
            '-i', $localPath,
            '-frames:v', '1',
            '-q:v', '3',
            $outputPath,
        ]);
        $process->setTimeout(config('media.video.process_timeout_seconds'));

        return $this->runQuietly($process);
    }

    /**
     * Transcodes to an H.264/AAC MP4 sized within Telegram's practical limits.
     * Only invoked when VideoIngestionPlan::$shouldConsiderTranscode is true
     * AND probe() reports a codec/container Telegram can't play natively.
     */
    public function transcodeForTelegram(string $localPath, string $outputPath, int $maxHeight = 720): bool
    {
        $process = new Process([
            config('media.video.ffmpeg_binary'),
            '-y',
            '-i', $localPath,
            '-vf', "scale=-2:'min({$maxHeight},ih)'",
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '23',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            $outputPath,
        ]);
        $process->setTimeout(config('media.video.process_timeout_seconds'));

        return $this->runQuietly($process);
    }

    private function runQuietly(Process $process): bool
    {
        try {
            $process->mustRun();

            return true;
        } catch (ProcessFailedException $e) {
            Log::warning('ffmpeg command failed', ['command' => $process->getCommandLine(), 'error' => $e->getMessage()]);

            return false;
        }
    }
}
