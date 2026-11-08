<?php

namespace App\Services;

use ZipArchive;

/**
 * Reads the first sheet of an .xlsx file (or a .csv) into rows of strings, with no extra
 * package: an .xlsx is a zip of XML files.
 */
class SpreadsheetReader
{
    /** @return array<int, array<int, string>> */
    public function read(string $path, string $extension): array
    {
        return strtolower($extension) === 'xlsx' ? $this->xlsx($path) : $this->csv($path);
    }

    private function csv(string $path): array
    {
        $text = (string) file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1256');
        }

        $firstLine = strtok($text, "\n");
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : (str_contains($firstLine, "\t") ? "\t" : ',');

        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($v) => trim((string) $v), $row);
        }
        fclose($fh);

        return $rows;
    }

    private function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return [];
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $doc = simplexml_load_string($xml);
            foreach ($doc->si as $si) {
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) {
            return [];
        }

        $rows = [];
        $doc = simplexml_load_string($sheet);
        foreach ($doc->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = $this->columnIndex((string) $c['r']);
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) $c->is->t,
                    default => (string) $c->v,
                };
                $cells[$col] = trim($value);
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $rows[] = array_map(fn ($i) => $cells[$i] ?? '', range(0, $max));
            }
        }

        return $rows;
    }

    private function columnIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/', $ref, $m);
        $index = 0;
        foreach (str_split($m[0] ?? 'A') as $ch) {
            $index = $index * 26 + (ord($ch) - 64);
        }

        return $index - 1;
    }
}
