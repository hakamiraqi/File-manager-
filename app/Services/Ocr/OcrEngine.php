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

    public function magick(): ?string
    {
        return $this->locate('magick', config('services.ocr.magick'), [base_path('../imagemagick/magick.exe')]);
    }

    /**
     * Straightens tilted scans and evens out faded ink before reading (ImageMagick -deskew
     * -normalize), in place and side by side. Tested on the library's scans (2026-11-12): up to
     * 4 points more clean words on messy pages, the same on clean ones; the other clean-ups
     * tried (unpaper, shadow removal, thresholds, 400 dpi, single-block layout) were not better.
     * The page keeps its exact size so the searchable text layer lines up with the original.
     * A page that fails to clean is simply read as it was.
     */
    private function straighten(array $images, float $deadline): void
    {
        $magick = $this->magick();
        if ($magick === null || $images === []) {
            return;
        }
        $timeout = $this->remaining($deadline);
        Process::pool(function (Pool $pool) use ($images, $magick, $timeout) {
            foreach ($images as $i => $image) {
                [$w, $h] = @getimagesize($image) ?: [0, 0];
                if ($w < 1 || $h < 1) {
                    continue;
                }
                $pool->as((string) $i)->timeout($timeout)->env(['MAGICK_THREAD_LIMIT' => '1'])->command([
                    ...$this->nice(), $magick, $image, '-deskew', '40%', '+repage', '-normalize',
                    '-gravity', 'center', '-background', 'white', '-extent', "{$w}x{$h}", $image.'.clean.png',
                ]);
            }
        })->start()->wait();
        foreach ($images as $image) {
            $clean = $image.'.clean.png';
            if (is_file($clean) && filesize($clean) > 0) {
                @rename($clean, $image);
            } else {
                @unlink($clean);
            }
        }
    }

    /** qpdf lays the recognised text under the scanned pages (searchable PDF). */
    public function qpdf(): ?string
    {
        return $this->locate('qpdf', config('services.ocr.qpdf'), [base_path('../qpdf/bin/qpdf.exe')]);
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
            'best' => $this->bestDir() !== null,
            'searchable_pdf' => $this->qpdf() !== null,
            'cleanup' => $this->magick() !== null,
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
     * Recognises the text of one file. For a PDF, when $searchablePdf is given (and qpdf is
     * installed), it also writes a copy of the PDF there with the recognised text laid invisibly
     * under each scanned page, so it can be searched and copied from in any PDF reader.
     *
     * @return array{text: string, pages: int, searchable?: bool}
     */
    public function recognize(string $path, string $extension, string $languages, int $timeoutSeconds, int $maxPages = 300, ?string $quality = null, ?string $searchablePdf = null): array
    {
        $bin = $this->tesseract() ?? throw new RuntimeException('برنامج التعرف على النصوص (Tesseract) غير مثبت.');
        $this->best = ($quality ?? OcrSettings::get('ocr_quality')) === 'best' ? $this->bestDir() : null;
        $this->pageLayer = null;
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
        $this->dpi = $this->best ? 300 : 200;
        if ($searchablePdf !== null && $this->qpdf() !== null) {
            $this->pageLayer = $work.'/layer';
            Fs::ensureDirectoryExists($this->pageLayer);
        }
        $build = false;
        $searchable = false;
        $step = $this->parallel();
        $texts = [];
        $read = 0;
        $partial = false;
        try {
            for ($first = 1; $first <= $last; $first += $step) {
                $to = min($last, $first + $step - 1);
                $r = Process::timeout($this->remaining($deadline))
                    ->run([...$this->nice(), $pdftoppm, '-r', (string) $this->dpi, '-gray', '-png', '-f', (string) $first, '-l', (string) $to, $path, $work.'/p']);
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
                $this->straighten($images->map(fn ($f) => $f->getPathname())->all(), $deadline);
                foreach ($this->tesseractMany($bin, $images->map(fn ($f) => $f->getPathname())->all(), $langs, $deadline) as $text) {
                    $texts[] = $text;
                }
                $images->each(fn ($f) => @unlink($f->getPathname()));
                $read += $images->count();
                if ($total === null && $images->count() < $to - $first + 1) {
                    break;
                }
            }
            $build = true;
        } catch (ProcessTimedOutException|RuntimeException $e) {
            if ($read === 0 || ! $this->isTimeout($e)) {
                throw $e;
            }
            $partial = true;
            $build = true; // the pages read so far still get their text layer
        } finally {
            if ($build && $this->pageLayer !== null) {
                try {
                    $searchable = $this->buildSearchablePdf($path, $this->pageLayer, (string) $searchablePdf);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Searchable PDF not made: '.$e->getMessage());
                }
            }
            $this->pageLayer = null;
            Fs::deleteDirectory($work);
        }

        return ['text' => trim(implode("\n\n", array_filter($texts, fn ($t) => trim($t) !== ''))), 'pages' => $read, 'total' => $total, 'partial' => $partial || ($total !== null && $read < $total), 'searchable' => $searchable];
    }

    /**
     * Joins the per-page text layers and lays them over the original PDF (qpdf --overlay):
     * the pages look exactly the same, but now carry invisible, selectable text. The result
     * replaces $out only when it opens cleanly and has as many pages as the original.
     */
    private function buildSearchablePdf(string $original, string $layerDir, string $out): bool
    {
        $qpdf = $this->qpdf();
        $pages = glob($layerDir.'/*.pdf') ?: [];
        if ($qpdf === null || $pages === []) {
            return false;
        }
        sort($pages, SORT_NATURAL);
        $layer = $layerDir.'/all.pdf';
        $r = Process::timeout(600)->run([$qpdf, '--empty', '--pages', ...$pages, '--', $layer]);
        if (! in_array($r->exitCode(), [0, 3], true) || ! is_file($layer)) {
            throw new RuntimeException('joining text pages: '.Str::limit(trim($r->errorOutput()), 200));
        }
        Fs::ensureDirectoryExists(dirname($out));
        $tmp = $out.'.part';
        $r = Process::timeout(900)->run([$qpdf, $original, '--overlay', $layer, '--', $tmp]);
        // qpdf exits 3 for "done, with warnings" (common with scanner-made PDFs).
        if (! in_array($r->exitCode(), [0, 3], true) || ! is_file($tmp) || $this->pageCount($tmp) !== $this->pageCount($original)) {
            @unlink($tmp);
            throw new RuntimeException('overlay: '.Str::limit(trim($r->errorOutput()), 200));
        }
        @unlink($out);
        rename($tmp, $out);

        return true;
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
                    ->command($this->tesseractCommand($bin, $image, $langs));
            }
        })->start()->wait();
        $out = [];
        foreach (array_keys($images) as $i) {
            $r = $results[(string) $i];
            if (! $r->successful()) {
                throw new RuntimeException('فشل التعرف: '.Str::limit(trim($r->errorOutput()), 200));
            }
            $out[] = $this->pageText($images[$i], $r->output());
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
            ->run($this->tesseractCommand($bin, $image, $langs));
        if (! $r->successful()) {
            throw new RuntimeException('فشل التعرف: '.Str::limit(trim($r->errorOutput()), 200));
        }

        return $this->pageText($image, $r->output());
    }

    /** Text to stdout, or (making a searchable PDF) text + an invisible-text PDF page to files. */
    private function tesseractCommand(string $bin, string $image, string $langs): array
    {
        $base = [...$this->nice(), $bin, ...($this->best ? ['--tessdata-dir', $this->best] : []), $image];
        if ($this->pageLayer === null) {
            return [...$base, 'stdout', '-l', $langs, '--psm', '3'];
        }

        return [...$base, $this->layerBase($image), '-l', $langs, '--psm', '3', '--dpi', (string) $this->dpi,
            '-c', 'tessedit_create_txt=1', '-c', 'tessedit_create_pdf=1', '-c', 'textonly_pdf=1'];
    }

    private function pageText(string $image, string $stdout): string
    {
        if ($this->pageLayer === null) {
            return $stdout;
        }
        $txt = $this->layerBase($image).'.txt';
        $text = is_file($txt) ? (string) file_get_contents($txt) : '';
        @unlink($txt);

        return $text;
    }

    /** p-012.png → <layer>/00012 (page order for joining). */
    private function layerBase(string $image): string
    {
        $page = preg_match('/(\d+)\.png$/i', $image, $m) ? (int) $m[1] : crc32($image);

        return $this->pageLayer.DIRECTORY_SEPARATOR.sprintf('%05d', $page);
    }

    /** Where this reading's per-page text layers go (null: no searchable PDF wanted). */
    private ?string $pageLayer = null;

    private int $dpi = 200;

    /** The «best» model folder for this reading, when chosen and installed. */
    private ?string $best = null;

    /** Folder with the accurate Arabic model, if it is installed here. */
    public function bestDir(): ?string
    {
        $dir = rtrim((string) config('services.ocr.tessdata_best'), '/\\');

        return $dir !== '' && is_file($dir.DIRECTORY_SEPARATOR.'ara.traineddata') ? $dir : null;
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
