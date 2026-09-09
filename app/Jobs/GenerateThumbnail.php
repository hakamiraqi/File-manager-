<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\ThumbnailGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $fileId) {}

    public function handle(ThumbnailGenerator $generator): void
    {
        $file = File::find($this->fileId);

        if (! $file || ! $generator->isEnabled() || ! $generator->supports((string) $file->extension)) {
            return;
        }

        $absolutePath = $file->disk()->path($file->path);

        if (! is_file($absolutePath)) {
            return;
        }

        $jpeg = $generator->generate($absolutePath, (string) $file->extension);

        if ($jpeg === null) {
            return;
        }

        $thumbnailPath = $generator->randomThumbnailPath($file->user_id);
        $file->disk()->put($thumbnailPath, $jpeg);

        $file->forceFill(['thumbnail_path' => $thumbnailPath])->save();
    }
}
