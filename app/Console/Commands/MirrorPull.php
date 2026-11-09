<?php

namespace App\Console\Commands;

use App\Services\Exams\Mirror;
use Illuminate\Console\Command;

/** On a standby server: take what changed on the main exam server (scheduled every minute). */
class MirrorPull extends Command
{
    protected $signature = 'exams:mirror';

    protected $description = 'Copy the latest changes from the main exam server (standby servers only).';

    public function handle(Mirror $mirror): int
    {
        $r = $mirror->pull();
        if (isset($r['error'])) {
            $this->error($r['error']);

            return self::FAILURE;
        }
        $this->info(isset($r['skipped']) ? 'Not a standby server.' : 'Rows copied: '.$r['rows']);

        return self::SUCCESS;
    }
}
