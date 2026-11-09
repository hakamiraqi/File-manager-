<?php

namespace App\Console\Commands;

use App\Services\Exams\ServerWatch;
use Illuminate\Console\Command;

class WatchServer extends Command
{
    protected $signature = 'exams:watch';

    protected $description = 'Check the server (disk, memory, database, jobs, AMC, off-site backup) and alert the admins on their phones.';

    public function handle(ServerWatch $watch): int
    {
        $r = $watch->run();
        foreach ($r['problems'] as $text) {
            $this->warn($text);
        }
        $this->info($r['problems'] ? 'Problems: '.count($r['problems']).($r['sent'] ? ' — sent by '.implode(', ', $r['sent']) : '') : 'All well.');

        return self::SUCCESS;
    }
}
