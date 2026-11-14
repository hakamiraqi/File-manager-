<?php

namespace App\Services\Library\Marc;

/** Writes MARC records as ISO 2709 (UTF-8, leader/09 = a) or MARCXML (2026-11-14). */
class MarcWriter
{
    public static function iso(MarcRecord $record): string
    {
        $directory = '';
        $data = '';
        foreach ($record->fields as $f) {
            if (isset($f['data'])) {
                $body = $f['data']."\x1E";
            } else {
                $body = ($f['ind1'] ?? ' ').($f['ind2'] ?? ' ');
                foreach ($f['subfields'] ?? [] as [$c, $v]) {
                    $body .= "\x1F".$c.str_replace(["\x1D", "\x1E", "\x1F"], '', $v);
                }
                $body .= "\x1E";
            }
            $directory .= str_pad($f['tag'], 3, '0', STR_PAD_LEFT).sprintf('%04d%05d', strlen($body), strlen($data));
            $data .= $body;
        }
        $directory .= "\x1E";
        $base = 24 + strlen($directory);
        $length = $base + strlen($data) + 1;
        $leader = str_pad(substr($record->leader, 0, 24), 24);
        // Lengths, UTF-8, and the fixed "22" / "4500" every MARC record carries.
        $leader = sprintf('%05d', $length).substr($leader, 5, 4).'a22'.sprintf('%05d', $base).substr($leader, 17, 3).'4500';

        return $leader.$directory.$data."\x1D";
    }

    public static function xmlOpen(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<collection xmlns="http://www.loc.gov/MARC21/slim">'."\n";
    }

    public static function xmlClose(): string
    {
        return "</collection>\n";
    }

    public static function xml(MarcRecord $record, bool $alone = false): string
    {
        $e = fn (string $s) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<record'.($alone ? ' xmlns="http://www.loc.gov/MARC21/slim"' : '').">\n  <leader>".$e(str_pad(substr($record->leader, 0, 24), 24))."</leader>\n";
        foreach ($record->fields as $f) {
            if (isset($f['data'])) {
                $out .= '  <controlfield tag="'.$e($f['tag']).'">'.$e($f['data'])."</controlfield>\n";

                continue;
            }
            $out .= '  <datafield tag="'.$e($f['tag']).'" ind1="'.$e($f['ind1'] ?? ' ').'" ind2="'.$e($f['ind2'] ?? ' ').'">'."\n";
            foreach ($f['subfields'] ?? [] as [$c, $v]) {
                $out .= '    <subfield code="'.$e($c).'">'.$e($v)."</subfield>\n";
            }
            $out .= "  </datafield>\n";
        }

        return $out."</record>\n";
    }
}
