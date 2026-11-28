<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\Archive\ArchiveClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sorts every file that already has text into the archive categories (after the rules change). */
class ClassifyArchive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public function handle(ArchiveClassifier $classifier): void
    {
        File::query()->whereNull('deleted_at')->whereNotNull('content_text')
            ->select(['id', 'user_id', 'folder_id', 'name', 'content_text', 'archive_manual', 'archive_rule_id'])
            ->chunkById(100, function ($files) use ($classifier) {
                // Moving files on a mass re-run would surprise people; only new files are filed away.
                $files->each(fn (File $f) => $classifier->classify($f, allowMove: false));
            });
    }
}
