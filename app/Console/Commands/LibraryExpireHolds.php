<?php

namespace App\Console\Commands;

use App\Services\Library\HoldService;
use Illuminate\Console\Command;

class LibraryExpireHolds extends Command
{
    protected $signature = 'library:expire-holds';

    protected $description = 'Pass set-aside copies that were not collected in time to the next reader';

    public function handle(HoldService $holds): int
    {
        $this->info('Expired holds: '.$holds->expireOverdue());

        return self::SUCCESS;
    }
}
