<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\DocumentTextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExtractFileText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $fileId) {}

    public function handle(DocumentTextExtractor $extractor): void
    {
        $file = File::find($this->fileId);

        if (! $file || ! $extractor->isEnabled() || ! $extractor->supports((string) $file->extension)) {
            return;
        }

        if ($file->size > $extractor->maxBytes()) {
            return;
        }

        $path = $file->disk()->path($file->path);

        if (! is_file($path)) {
            return;
        }

        $text = $extractor->extract($path, (string) $file->extension);

        $file->forceFill([
            'content_text' => $text,
            'content_indexed_at' => now(),
        ])->save();
    }
}
