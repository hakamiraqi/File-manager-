<?php

namespace App\Services\Library;

use App\Services\Covers\CoverFinder;
use App\Services\Ocr\OcrEngine;
use App\Services\Ocr\OcrSettings;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Reads the text printed on a book cover (2026-11-14) with the Tesseract already on the
 * server (so it works offline too), line by line with each line's letter height: the
 * biggest lines are almost always the title.
 */
class CoverText
{
    public function __construct(private OcrEngine $engine) {}

    public function available(): bool
    {
        return $this->engine->available();
    }

    /** @return array{lines: list<array{text: string, size: int}>, text: string, title: ?string, isbn: ?string} */
    public function read(string $imagePath): array
    {
        $bin = $this->engine->tesseract() ?? throw new RuntimeException('برنامج التعرف على النصوص (Tesseract) غير مثبت على الخادم.');
        $installed = $this->engine->installedLanguages();
        $wanted = explode('+', OcrSettings::get('ocr_languages') ?: 'ara+eng');
        $langs = implode('+', ($installed ? array_values(array_intersect($wanted, $installed)) : $wanted) ?: ['eng']);

        // --psm 11: scattered text, as on a cover, not paragraphs.
        $r = Process::timeout(60)->run([$bin, $imagePath, 'stdout', '-l', $langs, '--psm', '11', 'tsv']);
        if (! $r->successful() && trim($r->output()) === '') {
            throw new RuntimeException('تعذّر استخراج النص من الصورة.');
        }

        $lines = self::lines($r->output());
        $text = implode("\n", array_column($lines, 'text'));

        return ['lines' => $lines, 'text' => $text, 'title' => self::title($lines), 'isbn' => CoverFinder::isbns($text)[0] ?? null];
    }

    /** Tesseract's TSV → lines in reading order, each with its letter height. @return list<array{text: string, size: int}> */
    public static function lines(string $tsv): array
    {
        $groups = [];
        foreach (preg_split('/\R/', $tsv) as $row) {
            $c = explode("\t", $row);
            if (count($c) < 12 || $c[0] !== '5') {
                continue;
            }
            $word = trim($c[11]);
            if ($word === '' || (float) $c[10] < 35) {
                continue;
            }
            $key = $c[1].'-'.$c[2].'-'.$c[3].'-'.$c[4];
            $groups[$key]['words'][] = $word;
            $groups[$key]['heights'][] = (int) $c[9];
            $groups[$key]['top'] = min($groups[$key]['top'] ?? PHP_INT_MAX, (int) $c[7]);
        }

        $lines = [];
        foreach ($groups as $g) {
            $text = trim(implode(' ', $g['words']));
            // Specks and frames read as one or two stray signs.
            if (mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $text)) < 2) {
                continue;
            }
            sort($g['heights']);
            $lines[] = ['text' => $text, 'size' => $g['heights'][intdiv(count($g['heights']), 2)], 'top' => $g['top']];
        }
        usort($lines, fn ($a, $b) => $a['top'] <=> $b['top']);

        return array_map(fn ($l) => ['text' => $l['text'], 'size' => $l['size']], $lines);
    }

    /** The biggest line, with its neighbours of about the same size (a title on two or three lines). */
    public static function title(array $lines): ?string
    {
        $words = array_values(array_filter($lines, fn ($l) => preg_match('/\p{L}{3,}/u', $l['text'])));
        if (! $words) {
            return null;
        }
        $max = max(array_column($words, 'size'));
        $at = array_search($max, array_column($words, 'size'), true);
        $from = $to = $at;
        while ($from > 0 && $words[$from - 1]['size'] >= $max * 0.7 && $at - $from < 2) {
            $from--;
        }
        while ($to < count($words) - 1 && $words[$to + 1]['size'] >= $max * 0.7 && $to - $from < 2) {
            $to++;
        }

        return trim(implode(' ', array_column(array_slice($words, $from, $to - $from + 1), 'text')));
    }
}
