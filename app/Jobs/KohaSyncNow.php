<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/** «زامن الآن» on the Koha settings. */
class KohaSyncNow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public bool $full = false) {}

    public function handle(): void
    {
        Artisan::call('library:koha-sync', $this->full ? ['--full' => true] : []);
    }
}
