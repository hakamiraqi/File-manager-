<?php

namespace App\Console\Commands;

use App\Jobs\GenerateThumbnail;
use App\Models\File;
use App\Services\ThumbnailGenerator;
use Illuminate\Console\Command;

/**
 * Previews for files that have none (2026-11-11): PDFs never got one on the server (it has
 * Poppler, not Imagick), nor did files brought in by the Drive import. Queued; uploads keep
 * working while it runs.
 */
class MakeThumbnails extends Command
{
    protected $signature = 'filenest:make-thumbnails {--ext= : Only this extension, e.g. pdf}';

    protected $description = 'Queue a preview image for every file that can have one and has none';

    public function handle(ThumbnailGenerator $generator): int
    {
        if (! $generator->isEnabled()) {
            $this->warn('Thumbnails are switched off in the settings.');

            return self::SUCCESS;
        }

        $count = 0;
        File::query()
            ->whereNull('thumbnail_path')
            ->when($this->option('ext'), fn ($q, $ext) => $q->where('extension', strtolower((string) $ext)))
            ->select(['id', 'extension'])
            ->chunkById(500, function ($files) use ($generator, &$count) {
                foreach ($files as $file) {
                    if ($generator->supports((string) $file->extension)) {
                        GenerateThumbnail::dispatch($file->id);
                        $count++;
                    }
                }
            });
        $this->info("Queued {$count} files.");

        return self::SUCCESS;
    }
}
