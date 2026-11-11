<?php

namespace App\Jobs;

use App\Services\Ocr\OcrEngine;
use App\Services\Ocr\QariClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** One page of the Qari trial, read by each engine chosen, one after another. */
class QariTrialRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1700; // the queue worker stops a job at 1800 s

    public int $tries = 1;

    public function __construct(public string $id) {}

    public static function key(string $id): string
    {
        return "qari-trial:$id";
    }

    public function handle(OcrEngine $tesseract, QariClient $qari): void
    {
        $trial = Cache::get(self::key($this->id));
        if (! $trial) {
            return;
        }
        $image = storage_path("app/private/qari-trial/{$this->id}.png");

        foreach ($trial['engines'] as $engine) {
            $this->put($engine, ['state' => 'running']);
            try {
                if (str_starts_with($engine, 'tesseract')) {
                    $start = microtime(true);
                    $r = $tesseract->recognize($image, 'png', 'ara+eng', 600, 300, $engine === 'tesseract-best' ? 'best' : 'fast');
                    $this->put($engine, ['state' => 'done', 'text' => trim($r['text']), 'seconds' => round(microtime(true) - $start, 1)]);
                } else {
                    $this->put($engine, ['state' => 'done', ...$qari->read($engine, $image)]);
                }
            } catch (\Throwable $e) {
                $this->put($engine, ['state' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)]);
            }
        }
    }

    private function put(string $engine, array $result): void
    {
        $trial = Cache::get(self::key($this->id));
        if (! $trial) {
            return;
        }
        $trial['results'][$engine] = $result;
        Cache::put(self::key($this->id), $trial, now()->addDays(7));
    }
}
