<?php

namespace App\Services;

use App\Models\File;
use App\Models\Setting;
use App\Models\VideoQuality;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Produces lower-resolution H.264 renditions of an uploaded video so
 * playback can offer a YouTube-style quality selector. Entirely optional,
 * following the exact same runtime-detection pattern as ThumbnailGenerator
 * and DocumentTextExtractor: no `ffmpeg` binary on PATH simply means no
 * variants are produced and the player falls back to the single original
 * file — uploads and playback are never blocked by its absence.
 */
class VideoTranscoder
{
    public function isEnabled(): bool
    {
        return (bool) Setting::get('video_transcoding_enabled', true);
    }

    /**
     * @return int[] Target heights in pixels, e.g. [360, 480, 720].
     */
    public function targetHeights(): array
    {
        $raw = (string) Setting::get('video_quality_targets', '360,480,720');

        $heights = array_filter(array_map(
            fn ($v) => (int) trim($v),
            explode(',', $raw)
        ));

        sort($heights);

        return array_values(array_unique($heights));
    }

    public function ffmpegAvailable(): bool
    {
        static $cached = null;

        return $cached ??= Process::run(['which', 'ffmpeg'])->successful();
    }

    /**
     * Transcodes $file into every configured target height that doesn't
     * already exist for it, storing each as its own row + file on disk.
     * Silently does nothing if disabled or ffmpeg is unavailable.
     */
    public function transcode(File $file): void
    {
        if (! $this->isEnabled() || ! $this->ffmpegAvailable()) {
            return;
        }

        $absolutePath = $file->disk()->path($file->path);

        if (! is_file($absolutePath)) {
            return;
        }

        $existingHeights = $file->qualities()->pluck('height')->all();

        foreach ($this->targetHeights() as $height) {
            if (in_array($height, $existingHeights, true)) {
                continue;
            }

            $this->transcodeOne($file, $absolutePath, $height);
        }
    }

    private function transcodeOne(File $file, string $absolutePath, int $height): void
    {
        $tmpOutput = tempnam(sys_get_temp_dir(), 'vq').'.mp4';
        @unlink($tmpOutput);

        try {
            // scale=-2:'min(ih,HEIGHT)' never upscales a source smaller than
            // the target — it just re-encodes at its native size instead.
            $result = Process::timeout(600)->run([
                'ffmpeg', '-y', '-i', $absolutePath,
                '-vf', "scale=-2:'min(ih,{$height})'",
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
                '-c:a', 'aac', '-b:a', '128k',
                '-movflags', '+faststart',
                $tmpOutput,
            ]);

            if (! $result->successful() || ! is_file($tmpOutput) || filesize($tmpOutput) === 0) {
                return;
            }

            $relativePath = "{$file->user_id}/video-qualities/".Str::uuid().'.mp4';
            $file->disk()->put($relativePath, file_get_contents($tmpOutput));

            VideoQuality::create([
                'file_id' => $file->id,
                'height' => $height,
                'path' => $relativePath,
                'size' => filesize($tmpOutput),
            ]);
        } catch (Throwable) {
            // A single failed rendition (unsupported codec, corrupt input,
            // timeout) never blocks the others or the original file.
        } finally {
            @unlink($tmpOutput);
        }
    }
}
