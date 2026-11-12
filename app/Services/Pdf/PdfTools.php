<?php

namespace App\Services\Pdf;

use App\Models\File;
use App\Services\LibreOfficeConverter;
use App\Services\Ocr\OcrEngine;
use App\Services\Office\OnlyOffice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * PDF tools (2026-11-12, phase 2 of the WPS study): merge, split, organise, rotate, compress,
 * protect / unlock, watermark, page numbers and conversions. Every tool works on copies in a
 * temp folder with the programs the server already has — qpdf, Poppler, ImageMagick,
 * Ghostscript, LibreOffice and the office editor — on the VPS and on the offline Windows server
 * (where the programs sit next to the app). A tool whose program is missing is reported as
 * unavailable rather than failing.
 *
 * Each tool returns the files it made: [['path' => temp path, 'name' => suggested name], …].
 */
class PdfTools
{
    public const TOOLS = [
        'merge', 'split', 'organize', 'rotate', 'compress', 'protect', 'unlock',
        'watermark', 'numbers', 'to_images', 'images_to_pdf', 'office_to_pdf', 'to_word', 'to_text',
    ];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff', 'heic'];

    public function __construct(
        private readonly OcrEngine $ocr,
        private readonly LibreOfficeConverter $libre,
        private readonly OnlyOffice $office,
    ) {}

    // ------------------------------------------------------------------ programs

    public function qpdf(): ?string
    {
        return $this->ocr->qpdf();
    }

    public function pdftoppm(): ?string
    {
        return $this->ocr->pdftoppm();
    }

    public function magick(): ?string
    {
        return $this->ocr->magick() ?? $this->which('convert', 'magick-convert');
    }

    public function poppler(string $name): ?string
    {
        $ppm = $this->pdftoppm();
        if ($ppm) {
            $sibling = dirname($ppm).DIRECTORY_SEPARATOR.$name.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            if (is_file($sibling)) {
                return $sibling;
            }
        }

        return $this->which($name, $name);
    }

    public function ghostscript(): ?string
    {
        $configured = config('services.pdf.gs');
        if ($configured && is_file($configured)) {
            return $configured;
        }
        foreach (glob(base_path('../ghostscript/bin/gswin64c.exe')) ?: [] as $bundled) {
            return $bundled;
        }

        return $this->which('gs', 'gs');
    }

    /** Which tools this server can run. @return array<string, bool> */
    public function capabilities(): array
    {
        $qpdf = $this->qpdf() !== null;
        $magick = $this->magick() !== null;
        $officeConvert = $this->libre->available() || $this->office->enabled();

        return [
            'merge' => $qpdf,
            'split' => $qpdf,
            'organize' => $qpdf,
            'rotate' => $qpdf,
            'compress' => $qpdf,
            'protect' => $qpdf,
            'unlock' => $qpdf,
            'watermark' => $qpdf && $magick,
            'numbers' => $qpdf && $this->poppler('pdfinfo') !== null,
            'to_images' => $this->pdftoppm() !== null,
            'images_to_pdf' => $magick,
            'office_to_pdf' => $officeConvert,
            'to_word' => $this->poppler('pdftotext') !== null || $this->office->enabled(),
            'to_text' => $this->poppler('pdftotext') !== null,
            // Inputs other than PDF in «merge».
            'merge_images' => $magick,
            'merge_office' => $officeConvert,
        ];
    }

    // ------------------------------------------------------------------ inputs

    public static function kindOf(File $file): string
    {
        $ext = strtolower((string) $file->extension);
        if ($ext === 'pdf' || $file->mime_type === 'application/pdf') {
            return 'pdf';
        }
        if (in_array($ext, self::IMAGE_EXTENSIONS, true) || str_starts_with((string) $file->mime_type, 'image/')) {
            return 'image';
        }
        if (in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp', 'ppsx'], true)) {
            return 'office';
        }

        return 'other';
    }

    /** The PDF to work from: its searchable copy when one was made, so results stay copyable. */
    public function pdfPath(File $file): string
    {
        return $file->searchablePdf() ?? $file->disk()->path($file->path);
    }

    /** Any supported file as a PDF in $dir (images and office files are converted). */
    public function asPdf(File $file, string $dir): string
    {
        $kind = self::kindOf($file);
        if ($kind === 'pdf') {
            return $this->pdfPath($file);
        }
        $out = $dir.'/in-'.$file->id.'-'.Str::random(6).'.pdf';
        if ($kind === 'image') {
            $this->imagesToPdf([$file->disk()->path($file->path)], $out);

            return $out;
        }
        if ($kind === 'office') {
            $this->officeToPdf($file, $out);

            return $out;
        }
        throw new RuntimeException('«'.$file->name.'» ليس PDF ولا صورة ولا مستنداً مكتبياً.');
    }

    public function pageCount(string $pdf): int
    {
        $r = $this->run([$this->need($this->qpdf(), 'qpdf'), '--show-npages', $pdf], 60, [0, 3]);

        return max(0, (int) trim($r));
    }

    // ------------------------------------------------------------------ tools

    /** @param list<string> $pdfs */
    public function merge(array $pdfs, string $out): void
    {
        $this->run([$this->need($this->qpdf(), 'qpdf'), '--empty', '--pages', ...$pdfs, '--', $out], 900, [0, 3]);
    }

    /**
     * Pages chosen from one PDF, in the given order, each turned by 0/90/180/270.
     *
     * @param  list<array{0:int,1:int}>  $pages  [page number, rotation]
     */
    public function organize(string $pdf, array $pages, string $out): void
    {
        $qpdf = $this->need($this->qpdf(), 'qpdf');
        $list = implode(',', array_map(fn ($p) => (int) $p[0], $pages));
        $args = [$qpdf, '--empty', '--pages', $pdf, $list, '--'];
        $turns = [];
        foreach ($pages as $i => $p) {
            $deg = ((int) $p[1] % 360 + 360) % 360;
            if ($deg) {
                $turns[$deg][] = $i + 1;
            }
        }
        foreach ($turns as $deg => $at) {
            $args[] = '--rotate=+'.$deg.':'.implode(',', $at);
        }
        $args[] = $out;
        $this->run($args, 900, [0, 3]);
    }

    /** One file per range: "1-3", "4", "7-z". */
    public function extract(string $pdf, string $range, string $out): void
    {
        $this->run([$this->need($this->qpdf(), 'qpdf'), '--empty', '--pages', $pdf, $range, '--', $out], 600, [0, 3]);
    }

    public function rotate(string $pdf, int $degrees, string $which, string $out): void
    {
        $range = match ($which) {
            'odd' => '1-z:odd',
            'even' => '1-z:even',
            default => '1-z',
        };
        $this->run([$this->need($this->qpdf(), 'qpdf'), $pdf, '--rotate=+'.(((int) $degrees % 360 + 360) % 360).':'.$range, '--', $out], 600, [0, 3]);
    }

    /** low: barely visible change; medium: e-book quality; high: smallest (screen). */
    public function compress(string $pdf, string $level, string $out): void
    {
        if ($gs = $this->ghostscript()) {
            $setting = ['low' => '/printer', 'medium' => '/ebook', 'high' => '/screen'][$level] ?? '/ebook';
            $this->run([
                $gs, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.6',
                '-dPDFSETTINGS='.$setting, '-dDetectDuplicateImages=true', '-dCompressFonts=true',
                '-sOutputFile='.$out, $pdf,
            ], 1800);
            if (is_file($out) && filesize($out) > 0) {
                return;
            }
        }
        // Without Ghostscript: re-pack the file's own streams (smaller, never lower quality).
        $this->run([
            $this->need($this->qpdf(), 'qpdf'), $pdf, '--object-streams=generate', '--compress-streams=y',
            '--recompress-flate', '--compression-level=9', '--remove-unreferenced-resources=yes', '--', $out,
        ], 900, [0, 3]);
    }

    /** AES-256 with the reader's password; optionally no printing, copying or changes. */
    public function protect(string $pdf, string $password, array $deny, string $out, string $dir): void
    {
        $owner = Str::random(32);
        $args = ['--encrypt', $password, $owner, '256'];
        if (in_array('print', $deny, true)) {
            $args[] = '--print=none';
        }
        if (in_array('copy', $deny, true)) {
            $args[] = '--extract=n';
        }
        if (in_array('modify', $deny, true)) {
            $args[] = '--modify=none';
        }
        $args[] = '--';
        // The password goes through a file, not the command line (other processes can read that).
        $argFile = $dir.'/args-'.Str::random(8);
        file_put_contents($argFile, implode("\n", [$pdf, ...$args, $out]));
        try {
            $this->run([$this->need($this->qpdf(), 'qpdf'), '@'.$argFile], 600, [0, 3]);
        } finally {
            @unlink($argFile);
        }
    }

    public function unlock(string $pdf, string $password, string $out, string $dir): void
    {
        $pass = $dir.'/pw-'.Str::random(8);
        file_put_contents($pass, $password);
        try {
            $r = Process::timeout(600)->run([$this->need($this->qpdf(), 'qpdf'), '--password-file='.$pass, '--decrypt', $pdf, $out]);
        } finally {
            @unlink($pass);
        }
        if (! in_array($r->exitCode(), [0, 3], true)) {
            if (str_contains($r->errorOutput(), 'password')) {
                throw new PdfToolException('كلمة المرور غير صحيحة.');
            }
            throw new PdfToolException('تعذّر فتح الملف: '.Str::limit(trim($r->errorOutput()), 200));
        }
    }

    public function isEncrypted(string $pdf): bool
    {
        $r = Process::timeout(60)->run([$this->need($this->qpdf(), 'qpdf'), '--is-encrypted', $pdf]);

        return $r->exitCode() === 0;
    }

    /**
     * A picture (drawn in the browser, so Arabic text is shaped correctly, or the reader's logo)
     * laid over or under every page, fitted to each page.
     */
    public function watermark(string $pdf, string $png, bool $under, string $pages, string $out, string $dir): void
    {
        $mark = $dir.'/mark.pdf';
        $this->run([$this->need($this->magick(), 'ImageMagick'), $png, $mark], 300);
        $args = [$this->need($this->qpdf(), 'qpdf'), $pdf, $under ? '--underlay' : '--overlay', $mark, '--repeat=1'];
        if ($pages === 'first') {
            $args[] = '--to=1';
        } elseif ($pages === 'odd' || $pages === 'even') {
            $args[] = '--to=1-z:'.$pages;
        }
        $args[] = '--';
        $args[] = $out;
        $this->run($args, 900, [0, 3]);
    }

    /**
     * Page numbers drawn as real text (sharp at any zoom, Helvetica), at the chosen corner.
     * $format: "n", "n / N", "- n -", "Page n of N".
     */
    public function numbers(string $pdf, array $o, string $out, string $dir): void
    {
        $sizes = $this->pageSizes($pdf);
        $total = count($sizes);
        $start = max(1, (int) ($o['start'] ?? 1));
        $from = max(1, (int) ($o['from'] ?? 1)); // first numbered page
        $font = min(36, max(6, (int) ($o['size'] ?? 11)));
        $pos = (string) ($o['position'] ?? 'bottom-center');
        $margin = 24;

        $objects = [];
        $kids = [];
        $add = function (string $body) use (&$objects) {
            $objects[] = $body;

            return count($objects);
        };
        $fontId = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $pagesId = $add(''); // filled in at the end
        foreach ($sizes as $i => [$w, $h]) {
            $n = $i + 1;
            $text = '';
            if ($n >= $from) {
                $num = $start + $n - $from;
                $last = $start + $total - $from;
                $text = match ($o['format'] ?? 'n') {
                    'n/N' => "{$num} / {$last}",
                    '-n-' => "- {$num} -",
                    'page' => "Page {$num} of {$last}",
                    default => (string) $num,
                };
            }
            $tw = $this->helveticaWidth($text) * $font;
            [$v, $hz] = array_pad(explode('-', $pos, 2), 2, 'center');
            $x = match ($hz) {
                'left' => $margin,
                'right' => $w - $margin - $tw,
                default => ($w - $tw) / 2,
            };
            $y = $v === 'top' ? $h - $margin - $font : $margin;
            $stream = $text === '' ? '' : sprintf("BT /F1 %d Tf 0.2 0.2 0.2 rg %.2F %.2F Td (%s) Tj ET", $font, $x, $y, $text);
            $contentId = $add('<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream");
            $kids[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>', $pagesId, $w, $h, $fontId, $contentId));
        }
        $objects[$pagesId - 1] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($k) => "$k 0 R", $kids)).'] /Count '.count($kids).' >>';
        $catalogId = $add("<< /Type /Catalog /Pages {$pagesId} 0 R >>");

        $pdfText = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdfText);
            $pdfText .= ($i + 1)." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdfText);
        $pdfText .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdfText .= sprintf("%010d 00000 n \n", $off);
        }
        $pdfText .= 'trailer << /Size '.(count($objects) + 1)." /Root {$catalogId} 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        $overlay = $dir.'/numbers.pdf';
        file_put_contents($overlay, $pdfText);

        $this->run([$this->need($this->qpdf(), 'qpdf'), $pdf, '--overlay', $overlay, '--', $out], 900, [0, 3]);
    }

    /** @return list<array{0:float,1:float}> each page's shown width and height in points */
    public function pageSizes(string $pdf): array
    {
        $info = $this->need($this->poppler('pdfinfo'), 'pdfinfo');
        $r = $this->run([$info, '-f', '1', '-l', '100000', $pdf], 120);
        $sizes = [];
        $rot = [];
        foreach (preg_split('/\R/', $r) as $line) {
            if (preg_match('/^Page\s+(\d+)\s+size:\s+([\d.]+)\s+x\s+([\d.]+)/', $line, $m)) {
                $sizes[(int) $m[1]] = [(float) $m[2], (float) $m[3]];
            } elseif (preg_match('/^Page\s+(\d+)\s+rot:\s+(\d+)/', $line, $m)) {
                $rot[(int) $m[1]] = (int) $m[2];
            }
        }
        if (! $sizes) {
            throw new PdfToolException('تعذّر قراءة صفحات الملف.');
        }
        ksort($sizes);
        $out = [];
        foreach ($sizes as $n => [$w, $h]) {
            // A page turned a quarter is numbered as it is shown.
            $out[] = (($rot[$n] ?? 0) % 180) ? [$h, $w] : [$w, $h];
        }

        return $out;
    }

    /** Every page as an image (png/jpg), in a zip. */
    public function toImages(string $pdf, string $format, int $dpi, string $out, string $dir): int
    {
        $prefix = $dir.'/page';
        $this->run([$this->need($this->pdftoppm(), 'pdftoppm'), '-r', (string) $dpi, $format === 'jpg' ? '-jpeg' : '-png', ...($format === 'jpg' ? ['-jpegopt', 'quality=88'] : []), $pdf, $prefix], 1800);
        $images = glob($prefix.'*.'.($format === 'jpg' ? 'jpg' : 'png')) ?: [];
        natsort($images);
        if (! $images) {
            throw new PdfToolException('لم تُستخرج أي صورة من الملف.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($out, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new PdfToolException('تعذّر إنشاء ملف مضغوط.');
        }
        foreach (array_values($images) as $i => $img) {
            $zip->addFile($img, sprintf('page-%03d.%s', $i + 1, $format === 'jpg' ? 'jpg' : 'png'));
            $zip->setCompressionName(sprintf('page-%03d.%s', $i + 1, $format === 'jpg' ? 'jpg' : 'png'), \ZipArchive::CM_STORE);
        }
        $zip->close();

        return count($images);
    }

    /** @param list<string> $images in order */
    public function imagesToPdf(array $images, string $out): void
    {
        $magick = $this->need($this->magick(), 'ImageMagick');
        $args = [$magick];
        foreach ($images as $img) {
            $args[] = $img.'[0]'; // first frame of GIF/TIFF
        }
        array_push($args, '-auto-orient', '-background', 'white', '-alpha', 'remove', '-alpha', 'off', '-quality', '90', $out);
        $this->run($args, 900);
    }

    public function officeToPdf(File $file, string $out): void
    {
        $ext = strtolower((string) $file->extension);
        if ($this->libre->available() && $this->libre->supports($ext) && $this->libre->toPdf($file->disk()->path($file->path), $out, $ext) && is_file($out)) {
            return;
        }
        if ($this->office->enabled()) {
            $bytes = $this->office->convert($file, 'pdf');
            if ($bytes !== null && str_starts_with($bytes, '%PDF')) {
                file_put_contents($out, $bytes);

                return;
            }
        }
        throw new PdfToolException('تعذّر تحويل «'.$file->name.'» إلى PDF على هذا الخادم.');
    }

    /** PDF to Word: the office editor's converter keeps the layout; otherwise the text, page by page. */
    public function toWord(File $file, string $pdf, string $out): string
    {
        if ($this->office->enabled()) {
            $bytes = $this->office->convert($file, 'docx');
            if ($bytes !== null && str_starts_with($bytes, 'PK')) {
                file_put_contents($out, $bytes);

                return 'layout';
            }
        }

        $word = new \PhpOffice\PhpWord\PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(12);
        $text = $this->toTextString($pdf);
        $pages = explode("\f", rtrim($text, "\f"));
        foreach ($pages as $i => $page) {
            $section = $word->addSection(['breakType' => 'nextPage']);
            foreach (preg_split('/\n\s*\n/u', trim($page)) as $para) {
                $para = trim(preg_replace('/[ \t]*\n[ \t]*/u', ' ', $para));
                if ($para === '') {
                    continue;
                }
                $rtl = (bool) preg_match('/\p{Arabic}/u', $para);
                $section->addText(htmlspecialchars($para, ENT_XML1), ['rtl' => $rtl], ['bidi' => $rtl, 'alignment' => $rtl ? 'right' : 'left', 'spaceAfter' => 120]);
            }
        }
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($out);

        return 'text';
    }

    public function toTextString(string $pdf): string
    {
        return $this->run([$this->need($this->poppler('pdftotext'), 'pdftotext'), '-enc', 'UTF-8', $pdf, '-'], 600);
    }

    // ------------------------------------------------------------------ helpers

    private function run(array $args, int $timeout, array $ok = [0]): string
    {
        $r = Process::timeout($timeout)->run($args);
        if (! in_array($r->exitCode(), $ok, true)) {
            $err = trim($r->errorOutput() ?: $r->output());
            if (str_contains($err, 'password') || str_contains($err, 'encrypted')) {
                throw new PdfToolException('الملف محمي بكلمة مرور. أزل الحماية أولاً بأداة «إزالة كلمة المرور».');
            }
            throw new PdfToolException('تعذّر تنفيذ العملية: '.Str::limit($err, 200));
        }

        return $r->output();
    }

    private function need(?string $bin, string $name): string
    {
        if ($bin === null) {
            throw new PdfToolException("هذه الأداة غير متوفرة على هذا الخادم ({$name}).");
        }

        return $bin;
    }

    private function which(string $key, string $name): ?string
    {
        return Cache::remember('filenest.pdf.bin.'.$key, 600, function () use ($name) {
            $r = Process::run(PHP_OS_FAMILY === 'Windows' ? ['where', $name] : ['which', $name]);
            $line = trim(strtok($r->output(), "\r\n") ?: '');

            return $r->successful() && $line !== '' ? $line : null;
        });
    }

    /** Helvetica advance widths (per 1 pt of font size) for the characters page numbers use. */
    private function helveticaWidth(string $text): float
    {
        $w = ['0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
            ' ' => 278, '-' => 333, '/' => 278, 'P' => 667, 'a' => 556, 'g' => 556, 'e' => 556, 'o' => 556, 'f' => 278];
        $sum = 0;
        foreach (str_split($text) as $c) {
            $sum += $w[$c] ?? 556;
        }

        return $sum / 1000;
    }
}
