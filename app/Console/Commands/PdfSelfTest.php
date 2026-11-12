<?php

namespace App\Console\Commands;

use App\Services\Pdf\PdfTools;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs every PDF tool this server can run on a small sample and says which work (2026-11-12).
 * The offline Windows package runs it after unpacking, so a tool that works on the VPS but not
 * with the Windows programs is caught before the package is published.
 */
class PdfSelfTest extends Command
{
    protected $signature = 'pdf:selftest {--require= : comma-separated tools that must work}';

    protected $description = 'Check the PDF tools (qpdf, Poppler, ImageMagick, Ghostscript) on this machine';

    public function handle(PdfTools $t): int
    {
        $dir = storage_path('app/tmp/pdf-selftest-'.Str::random(6));
        Fs::ensureDirectoryExists($dir);
        $in = $dir.'/in.pdf';
        file_put_contents($in, PdfTools::samplePdf(3));
        $caps = $t->capabilities();
        $failed = [];

        $check = function (string $tool, callable $fn) use (&$failed, $caps) {
            if (! ($caps[$tool] ?? false)) {
                $this->line(str_pad($tool, 14).' -  not available');

                return;
            }
            try {
                $note = $fn();
                $this->line(str_pad($tool, 14).' OK '.($note ?? ''));
            } catch (Throwable $e) {
                $failed[] = $tool;
                $this->error(str_pad($tool, 14).' FAILED '.Str::limit($e->getMessage(), 300));
            }
        };
        $pages = fn (string $p) => $t->pageCount($p);
        $must = function (bool $ok, string $why) {
            if (! $ok) {
                throw new \RuntimeException($why);
            }
        };

        $this->line('qpdf: '.($t->qpdf() ?? '-').' | pdftoppm: '.($t->pdftoppm() ?? '-').' | magick: '.($t->magick() ?? '-').' | gs: '.($t->ghostscript() ?? '-'));

        $check('merge', function () use ($t, $in, $dir, $pages, $must) {
            $t->merge([$in, $in], "$dir/m.pdf");
            $must($pages("$dir/m.pdf") === 6, 'expected 6 pages');
        });
        $check('split', function () use ($t, $in, $dir, $pages, $must) {
            $t->extract($in, '2-3', "$dir/s.pdf");
            $must($pages("$dir/s.pdf") === 2, 'expected 2 pages');
        });
        $check('organize', function () use ($t, $in, $dir, $pages, $must) {
            $t->organize($in, [[3, 90], [1, 0]], "$dir/o.pdf");
            $must($pages("$dir/o.pdf") === 2, 'expected 2 pages');
        });
        $check('rotate', fn () => $t->rotate($in, 90, 'odd', "$dir/r.pdf"));
        $check('compress', function () use ($t, $in, $dir) {
            $t->compress($in, 'medium', "$dir/c.pdf");

            return filesize("$dir/c.pdf").' bytes'.($t->ghostscript() ? ' (Ghostscript)' : ' (qpdf)');
        });
        $check('protect', function () use ($t, $in, $dir, $must) {
            $t->protect($in, 'كلمة-سر', ['print'], "$dir/p.pdf", $dir);
            $must($t->isEncrypted("$dir/p.pdf"), 'not encrypted');
            $t->unlock("$dir/p.pdf", 'كلمة-سر', "$dir/u.pdf", $dir);
            $must(! $t->isEncrypted("$dir/u.pdf"), 'still encrypted');
        });
        $check('numbers', function () use ($t, $in, $dir, $must) {
            $t->numbers($in, ['format' => 'n/N', 'position' => 'bottom-right'], "$dir/n.pdf", $dir);
            $t->numbers($in, ['format' => 'ar-page', 'position' => 'bottom-center'], "$dir/n-ar.pdf", $dir);
            $must($t->pageCount("$dir/n-ar.pdf") === 3, 'Arabic numbering lost pages');
            if ($t->poppler('pdftotext')) {
                $must((bool) preg_match('#2\s*/\s*3#', $t->toTextString("$dir/n.pdf")), 'page number not found');
            }
        });
        $check('watermark', function () use ($t, $in, $dir) {
            $img = imagecreatetruecolor(300, 400);
            imagesavealpha($img, true);
            imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
            imagefilledrectangle($img, 50, 150, 250, 250, imagecolorallocatealpha($img, 220, 0, 0, 90));
            imagepng($img, "$dir/mark.png");
            $t->watermark($in, "$dir/mark.png", false, 'all', "$dir/w.pdf", $dir);
        });
        $check('to_images', fn () => $t->toImages($in, 'png', 50, "$dir/i.zip", $dir).' images');
        $check('images_to_pdf', function () use ($t, $dir, $pages, $must) {
            $img = imagecreatetruecolor(200, 300);
            imagepng($img, "$dir/a.png");
            imagejpeg($img, "$dir/b.jpg");
            $t->imagesToPdf(["$dir/a.png", "$dir/b.jpg"], "$dir/ip.pdf");
            $must($pages("$dir/ip.pdf") === 2, 'expected 2 pages');
        });
        $check('to_text', fn () => $must(str_contains($t->toTextString($in), 'Hello page 2'), 'text not found'));
        $check('organize', function () use ($t, $in, $dir, $pages, $must) {
            $t->assemble([$in, $in], [[1, 0, 0], [0, 0, 0], [2, 90, 1], [3, 0, 0]], "$dir/as.pdf", $dir);
            $must($pages("$dir/as.pdf") === 4, 'expected 4 pages with a blank one');

            return 'with a blank page and pages from another file';
        });
        $check('remove_blank', function () use ($t, $dir, $must) {
            $blank = $t->blankPages("$dir/as.pdf", $dir);
            $must($blank === [2], 'expected page 2 blank, got '.json_encode($blank));
        });
        $check('crop', function () use ($t, $in, $dir, $must) {
            $n = $t->crop($in, [10, 10, 10, 10], false, 'all', "$dir/cr.pdf", $dir);
            $must($n === 3, 'expected 3 pages cropped');
            $n = $t->crop($in, [2, 2, 2, 2], true, 'all', "$dir/cr2.pdf", $dir);

            return "auto: {$n} pages";
        });
        $check('redact', function () use ($t, $in, $dir, $must) {
            $t->redact($in, [2 => [[0.05, 0.05, 0.9, 0.2]]], "$dir/rd.pdf", $dir);
            $must(! str_contains($t->toTextString("$dir/rd.pdf"), 'Hello page 2'), 'text still there');
            $must(str_contains($t->toTextString("$dir/rd.pdf"), 'Hello page 3'), 'other pages lost');
        });
        $check('repair', function () use ($t, $in, $dir) {
            $broken = "$dir/broken.pdf";
            file_put_contents($broken, preg_replace('/startxref\s+\d+/', 'startxref 12345', (string) file_get_contents($in)));

            return $t->repair($broken, "$dir/fixed.pdf");
        });
        $check('to_excel', fn () => $t->toExcel($in, "$dir/x.xlsx").' sheets');
        $check('to_pptx', fn () => $t->toPptx($in, "$dir/x.pptx", $dir).' slides');
        $check('extract_images', function () use ($t, $dir) {
            if (! is_file("$dir/ip.pdf")) {
                return 'skipped (no sample with images)';
            }

            return $t->extractImages("$dir/ip.pdf", "$dir/ei.zip", $dir).' images';
        });

        Fs::deleteDirectory($dir);
        $required = array_filter(explode(',', (string) $this->option('require')));
        $missing = array_filter($required, fn ($r) => ! ($caps[$r] ?? false));
        if ($missing) {
            $this->error('Required but not available: '.implode(', ', $missing));
        }

        return $failed || $missing ? self::FAILURE : self::SUCCESS;
    }
}
