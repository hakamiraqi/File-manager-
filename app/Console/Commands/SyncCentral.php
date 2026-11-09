<?php

namespace App\Console\Commands;

use App\Services\Exams\CentralSync;
use Illuminate\Console\Command;

/** On a hall server: take the central server's exams and send back results (when online). */
class SyncCentral extends Command
{
    protected $signature = 'exams:sync-central';

    protected $description = 'Exchange exams and results with the central exam server.';

    public function handle(CentralSync $sync): int
    {
        $r = $sync->run();
        if (isset($r['error'])) {
            $this->warn('Not now: '.$r['error']);

            return self::SUCCESS;   // no internet is normal for a hall server
        }
        $this->info(isset($r['skipped']) ? 'No central server set.' : "Exams taken: {$r['taken']}, results sent: {$r['sent']}");

        return self::SUCCESS;
    }
}
