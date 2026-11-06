<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrRunner;
use Illuminate\Console\Command;

/** Runs every minute from the scheduler; does nothing outside the work hours or when busy. */
class OcrRun extends Command
{
    protected $signature = 'ocr:run {--force : Ignore the work hours (not the load limits)} {--batch= : Files in this round}';

    protected $description = 'Recognise text in scanned images and PDFs (one round of the off-hours queue)';

    public function handle(OcrRunner $runner): int
    {
        $sample = $runner->run((bool) $this->option('force'), $this->option('batch') ? (int) $this->option('batch') : null);
        $this->line("{$sample->state}: {$sample->processed} file(s)".($sample->reason ? " ({$sample->reason})" : ''));

        return self::SUCCESS;
    }
}
