<?php

namespace App\Console\Commands;

use App\Services\Library\Koha\KohaSettings;
use App\Services\Library\Koha\KohaSync;
use Illuminate\Console\Command;

class LibraryKohaSync extends Command
{
    protected $signature = 'library:koha-sync {--full : Every record, not only those changed since the last run}';

    protected $description = 'Mirror Koha\'s catalogue, copies, loans and holds into the library (when Koha runs the lending)';

    public function handle(KohaSync $sync): int
    {
        if (! KohaSettings::enabled()) {
            $this->line('Koha link is off.');

            return self::SUCCESS;
        }
        $counts = $sync->run((bool) $this->option('full'));
        $this->info(json_encode($counts, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
