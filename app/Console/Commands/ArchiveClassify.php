<?php

namespace App\Console\Commands;

use App\Jobs\ClassifyArchive;
use Illuminate\Console\Command;

/** Sorts every file that has text into the archive categories (صادر، وارد…) again. */
class ArchiveClassify extends Command
{
    protected $signature = 'archive:classify';

    protected $description = 'Sort files with text into the archive categories again';

    public function handle(): int
    {
        ClassifyArchive::dispatchSync();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
