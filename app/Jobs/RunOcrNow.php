<?php

namespace App\Jobs;

use App\Services\Ocr\OcrRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** «شغّل الآن»: one short round outside the work hours, still respecting the load limits. */
class RunOcrNow implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1700;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $batch = 5) {}

    public function handle(OcrRunner $runner): void
    {
        $runner->run(force: true, batch: $this->batch);
    }
}
