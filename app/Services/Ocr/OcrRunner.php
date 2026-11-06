<?php

namespace App\Services\Ocr;

use App\Models\File;
use App\Models\OcrLoadSample;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The off-hours text recognition queue: finds scanned images and image-only PDFs, then works
 * through them one file at a time while the work window is open and the computer is not busy.
 */
class OcrRunner
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly OcrEngine $engine, private readonly LoadMonitor $monitor) {}

    /** Files that could be queued but are not yet. */
    public function candidates(): Builder
    {
        $maxBytes = max(1, OcrSettings::int('ocr_max_mb')) * 1024 * 1024;

        return File::query()->whereNull('ocr_status')->where('size', '<=', $maxBytes)
            ->where(function (Builder $q) {
                $q->whereIn('extension', [...OcrEngine::IMAGE_EXTENSIONS, ...array_map('strtoupper', OcrEngine::IMAGE_EXTENSIONS)])
                    ->orWhere(fn (Builder $p) => $p->whereIn('extension', ['pdf', 'PDF'])
                        // A PDF with a text layer is already searchable; only scans need OCR.
                        ->whereNotNull('content_indexed_at')
                        ->where(fn (Builder $t) => $t->whereNull('content_text')->orWhereRaw('LENGTH(content_text) < 100')));
            });
    }

    /** Marks new candidates as waiting. Returns how many were added. */
    public function enqueue(?int $limit = 1000): int
    {
        $ids = $this->candidates()->when($limit, fn ($q) => $q->limit($limit))->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        return File::whereIn('id', $ids)->update(['ocr_status' => 'pending', 'ocr_attempts' => 0, 'ocr_error' => null]);
    }

    /**
     * One round: queue new files, check the window and the load, then recognise up to a batch.
     * `$force` ignores the hours (the «شغّل الآن» button) but never the load limits.
     */
    public function run(bool $force = false, ?int $batch = null): OcrLoadSample
    {
        // A round that died half way leaves files "processing" forever; give them back.
        File::where('ocr_status', 'processing')->where('updated_at', '<', now()->subMinutes(max(30, OcrSettings::int('ocr_timeout_minutes') * 3)))
            ->update(['ocr_status' => 'pending']);

        $load = $this->monitor->sample();
        $record = fn (string $state, ?string $reason = null, int $processed = 0) => OcrLoadSample::create([...$load, 'state' => $state, 'reason' => $reason, 'processed' => $processed, 'created_at' => now()]);

        if (! OcrSettings::enabled()) {
            return $record('disabled');
        }
        if (! $this->engine->available()) {
            return $record('no_engine', 'برنامج Tesseract غير مثبت على هذا الحاسوب.');
        }
        $this->enqueue();
        if (! $force && ! OcrSettings::inWindow()) {
            return $record('outside_hours');
        }
        if ($reason = $this->monitor->busyReason($load)) {
            return $record('busy', $reason);
        }

        $processed = 0;
        $limit = $batch ?? max(1, OcrSettings::int('ocr_batch'));
        $stopReason = null;
        while ($processed < $limit) {
            $file = File::where('ocr_status', 'pending')->orderByDesc('ocr_priority')->orderBy('id')->first();
            if (! $file) {
                break;
            }
            $this->process($file);
            $processed++;

            if (! $force && ! OcrSettings::inWindow()) {
                $stopReason = 'انتهى وقت العمل المحدد.';
                break;
            }
            $now = $this->monitor->sample();
            if ($reason = $this->monitor->busyReason($now)) {
                $stopReason = $reason;
                $load = $now;
                break;
            }
        }

        $waiting = File::where('ocr_status', 'pending')->exists();

        return $record($processed === 0 && ! $waiting ? 'idle' : ($stopReason ? 'busy' : 'running'), $stopReason, $processed);
    }

    public function process(File $file): void
    {
        $file->forceFill(['ocr_status' => 'processing'])->save();
        $started = microtime(true);
        try {
            $path = $file->disk()->path($file->path);
            if (! is_file($path)) {
                throw new \RuntimeException('الملف غير موجود على القرص.');
            }
            $result = $this->engine->recognize(
                $path,
                (string) $file->extension,
                OcrSettings::get('ocr_languages') ?: 'ara+eng',
                max(1, OcrSettings::int('ocr_timeout_minutes')) * 60,
                max(1, OcrSettings::int('ocr_max_pages')),
            );
            $text = $this->tidy($result['text']);
            $existing = trim((string) $file->content_text);
            $merged = $existing !== '' && $text !== '' ? $existing."\n\n".$text : ($text !== '' ? $text : ($existing ?: null));

            $file->forceFill([
                'content_text' => $merged === null ? null : mb_substr($merged, 0, 2_000_000),
                'content_indexed_at' => now(),
                'ocr_status' => 'done',
                'ocr_error' => $text === '' ? 'لم يُعثر على نص مقروء.' : null,
                'ocr_pages' => $result['pages'],
                'ocr_seconds' => (int) round(microtime(true) - $started),
                'ocr_done_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $attempts = $file->ocr_attempts + 1;
            $file->forceFill([
                'ocr_status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                'ocr_attempts' => $attempts,
                'ocr_priority' => $file->ocr_priority - 1,
                'ocr_error' => Str::limit($e->getMessage(), 480),
                'ocr_seconds' => (int) round(microtime(true) - $started),
            ])->save();
            Log::warning("OCR failed for file {$file->id}: {$e->getMessage()}");
        }
    }

    private function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim(mb_scrub($text, 'UTF-8'));
    }
}
