<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads text out of scanned images and PDFs with Tesseract (free, works offline, Arabic and
 * English). PDF pages are first turned into images with pdftoppm (Poppler). Both programs ship
 * inside the Docker image and the offline Windows package, so nothing has to be installed by hand.
 */
class OcrEngine
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'jfif', 'png', 'tif', 'tiff', 'bmp', 'gif', 'webp', 'pnm'];

    public function supports(string $extension): bool
    {
        $extension = strtolower($extension);

        return in_array($extension, self::IMAGE_EXTENSIONS, true) || ($extension === 'pdf' && $this->pdftoppm() !== null);
    }

    public function available(): bool
    {
        return $this->tesseract() !== null;
    }

    public function tesseract(): ?string
    {
        return $this->locate('tesseract', config('services.ocr.tesseract'), [base_path('../tesseract/tesseract.exe')]);
    }

    public function pdftoppm(): ?string
    {
        return $this->locate('pdftoppm', config('services.ocr.pdftoppm'), [base_path('../poppler/pdftoppm.exe')]);
    }

    /** Engine details for the admin page. */
    public function info(): array
    {
        $bin = $this->tesseract();

        return [
            'available' => $bin !== null,
            'version' => $bin ? $this->version($bin) : null,
            'languages' => $bin ? $this->installedLanguages() : [],
            'pdf' => $this->pdftoppm() !== null,
        ];
    }

    /** @return list<string> */
    public function installedLanguages(): array
    {
        $bin = $this->tesseract();
        if ($bin === null) {
            return [];
        }

        return Cache::remember('filenest.ocr.langs.'.md5($bin), 600, function () use ($bin) {
            $r = Process::timeout(30)->run([$bin, '--list-langs']);
            $out = $r->output().$r->errorOutput();

            return collect(preg_split('/\R/', $out))->map(fn ($l) => trim($l))
                ->filter(fn ($l) => $l !== '' && preg_match('/^[a-z_]{3,}$/i', $l) && $l !== 'osd')->values()->all();
        });
    }

    /**
     * Recognises the text of one file.
     *
     * @return array{text: string, pages: int}
     */
    public function recognize(string $path, string $extension, string $languages, int $timeoutSeconds, int $maxPages = 300): array
    {
        $bin = $this->tesseract() ?? throw new RuntimeException('برنامج التعرف على النصوص (Tesseract) غير مثبت.');
        $langs = $this->usableLanguages($languages);
        $deadline = microtime(true) + $timeoutSeconds;

        if (strtolower($extension) !== 'pdf') {
            return ['text' => $this->tesseractRun($bin, $path, $langs, $deadline), 'pages' => 1];
        }

        $pdftoppm = $this->pdftoppm() ?? throw new RuntimeException('برنامج تحويل صفحات PDF (pdftoppm) غير مثبت.');
        $work = storage_path('app/tmp/ocr-'.Str::random(16));
        Fs::ensureDirectoryExists($work);
        // A few pages at a time (2026-11-10): all 300 pages turned into pictures before reading
        // the first one took most of the time allowed, and a long scanned book then failed with
        // nothing read, again and again. Now pages are turned and read a handful at a time, side
        // by side on the spare processor cores, and when the time runs out the pages already read
        // are kept («partial»), so a long book still becomes searchable.
        $total = $this->pageCount($path);
        $last = min(max(1, $maxPages), $total ?? max(1, $maxPages));
        $step = $this->parallel();
        $texts = [];
        $read = 0;
        $partial = false;
        try {
            for ($first = 1; $first <= $last; $first += $step) {
                $to = min($last, $first + $step - 1);
                $r = Process::timeout($this->remaining($deadline))
                    ->run([...$this->nice(), $pdftoppm, '-r', '200', '-gray', '-png', '-f', (string) $first, '-l', (string) $to, $path, $work.'/p']);
                if (! $r->successful()) {
                    if ($read === 0) {
                        throw new RuntimeException('تعذر فتح ملف PDF: '.Str::limit(trim($r->errorOutput()), 200));
                    }
                    break;
                }
                $images = collect(Fs::files($work))->filter(fn ($f) => strtolower($f->getExtension()) === 'png')
                    ->sortBy(fn ($f) => $f->getFilename(), SORT_NATURAL)->values();
                if ($images->isEmpty()) {
                    break; // past the last page (the count was unknown)
                }
                foreach ($this->tesseractMany($bin, $images->map(fn ($f) => $f->getPathname())->all(), $langs, $deadline) as $text) {
                    $texts[] = $text;
                }
                $images->each(fn ($f) => @unlink($f->getPathname()));
                $read += $images->count();
                if ($total === null && $images->count() < $to - $first + 1) {
                    break;
                }
            }
        } catch (ProcessTimedOutException|RuntimeException $e) {
            if ($read === 0 || ! $this->isTimeout($e)) {
                throw $e;
            }
            $partial = true;
        } finally {
            Fs::deleteDirectory($work);
        }

        return ['text' => trim(implode("\n\n", array_filter($texts, fn ($t) => trim($t) !== ''))), 'pages' => $read, 'total' => $total, 'partial' => $partial || ($total !== null && $read < $total)];
    }

    /** Several page images read side by side; their texts in page order. @return list<string> */
    private function tesseractMany(string $bin, array $images, string $langs, float $deadline): array
    {
        if (count($images) === 1) {
            return [$this->tesseractRun($bin, $images[0], $langs, $deadline)];
        }
        $timeout = $this->remaining($deadline);
        $results = Process::pool(function (Pool $pool) use ($images, $bin, $langs, $timeout) {
            foreach ($images as $i => $image) {
                $pool->as((string) $i)->timeout($timeout)->env(['OMP_THREAD_LIMIT' => '1'])
                    ->command([...$this->nice(), $bin, $image, 'stdout', '-l', $langs, '--psm', '3']);
            }
        })->start()->wait();
        $out = [];
        foreach (array_keys($images) as $i) {
            $r = $results[(string) $i];
            if (! $r->successful()) {
                throw new RuntimeException('فشل التعرف: '.Str::limit(trim($r->errorOutput()), 200));
            }
            $out[] = $r->output();
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('تجاوز الوقت المسموح لهذا الملف.');
        }

        return $out;
    }

    private function isTimeout(\Throwable $e): bool
    {
        return $e instanceof ProcessTimedOutException || str_contains($e->getMessage(), 'تجاوز الوقت');
    }

    /** Pages in a PDF (pdfinfo, beside pdftoppm), or null when it can't be told. */
    public function pageCount(string $path): ?int
    {
        $bin = $this->locate('pdfinfo', config('services.ocr.pdfinfo'), [base_path('../poppler/pdfinfo.exe')]);
        if ($bin === null) {
            return null;
        }
        $r = Process::timeout(60)->run([$bin, $path]);

        return $r->successful() && preg_match('/^Pages:\s+(\d+)/mi', $r->output(), $m) ? (int) $m[1] : null;
    }

    /** Pages read at once: the cores but one, at most 3 (Tesseract uses one core each here). */
    private function parallel(): int
    {
        $cores = (int) (getenv('NUMBER_OF_PROCESSORS') ?: 0);
        if ($cores < 1 && PHP_OS_FAMILY !== 'Windows') {
            $cores = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
        }

        return max(1, min(3, ($cores ?: 2) - 1));
    }

    private function tesseractRun(string $bin, string $image, string $langs, float $deadline): string
    {
        $r = Process::timeout($this->remaining($deadline))
            ->env(['OMP_THREAD_LIMIT' => '1'])
            ->run([...$this->nice(), $bin, $image, 'stdout', '-l', $langs, '--psm', '3']);
        if (! $r->successful()) {
            throw new RuntimeException('فشل التعرف: '.Str::limit(trim($r->errorOutput()), 200));
        }

        return $r->output();
    }

    /** The requested languages that are installed (Arabic + English by default). */
    private function usableLanguages(string $languages): string
    {
        $installed = $this->installedLanguages();
        $wanted = array_values(array_filter(array_map('trim', explode('+', $languages))));
        $ok = $installed === [] ? $wanted : array_values(array_intersect($wanted, $installed));
        if ($ok === []) {
            throw new RuntimeException('لغات التعرف المطلوبة ('.$languages.') غير مثبتة.');
        }

        return implode('+', $ok);
    }

    private function remaining(float $deadline): int
    {
        $left = (int) floor($deadline - microtime(true));
        if ($left < 1) {
            throw new RuntimeException('تجاوز الوقت المسموح لهذا الملف.');
        }

        return $left;
    }

    /** Lowest CPU priority on Linux so the site stays quick. */
    private function nice(): array
    {
        static $nice = null;
        if (PHP_OS_FAMILY === 'Windows') {
            return [];
        }
        $nice ??= Process::run(['which', 'nice'])->successful() ? ['nice', '-n', '19'] : [];

        return $nice;
    }

    private function version(string $bin): ?string
    {
        return Cache::remember('filenest.ocr.version.'.md5($bin), 600, function () use ($bin) {
            $r = Process::timeout(30)->run([$bin, '--version']);
            preg_match('/tesseract\s+v?([\d.]+)/i', $r->output().$r->errorOutput(), $m);

            return $m[1] ?? null;
        });
    }

    private function locate(string $name, ?string $configured, array $bundled): ?string
    {
        if ($configured && is_file($configured)) {
            return $configured;
        }
        foreach ($bundled as $path) {
            if (is_file($path)) {
                return realpath($path) ?: $path;
            }
        }

        return Cache::remember('filenest.ocr.bin.'.$name, 600, function () use ($name) {
            $r = Process::run(PHP_OS_FAMILY === 'Windows' ? ['where', $name] : ['which', $name]);
            $line = trim(strtok($r->output(), "\r\n") ?: '');

            return $r->successful() && $line !== '' ? $line : null;
        });
    }
}
