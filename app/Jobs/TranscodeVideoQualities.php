<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\VideoTranscoder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TranscodeVideoQualities implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(public readonly int $fileId) {}

    public function handle(VideoTranscoder $transcoder): void
    {
        $file = File::find($this->fileId);

        if (! $file || ! $file->isVideo()) {
            return;
        }

        $transcoder->transcode($file);
    }
}
