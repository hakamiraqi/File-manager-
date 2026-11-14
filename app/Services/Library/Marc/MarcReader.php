<?php

namespace App\Services\Library\Marc;

use Generator;
use RuntimeException;
use XMLReader;

/**
 * Reads MARC files (2026-11-14): ISO 2709 (.mrc, what Koha and most library systems export)
 * and MARCXML. Records come one at a time, so a catalogue of tens of thousands of books
 * is read without holding it all in memory.
 */
class MarcReader
{
    private const FT = "\x1E";   // field terminator

    private const RT = "\x1D";   // record terminator

    private const SD = "\x1F";   // subfield delimiter

    /** @return Generator<int, MarcRecord> */
    public static function file(string $path): Generator
    {
        $fh = fopen($path, 'rb');
        if (! $fh) {
            throw new RuntimeException('تعذّر فتح الملف.');
        }
        $head = (string) fread($fh, 512);
        fclose($fh);
        $trim = ltrim($head, "\xEF\xBB\xBF \t\r\n");

        yield from str_starts_with($trim, '<') ? self::xmlFile($path) : self::isoFile($path);
    }

    /** @return Generator<int, MarcRecord> */
    public static function isoFile(string $path): Generator
    {
        $fh = fopen($path, 'rb');
        $buffer = '';
        while (! feof($fh)) {
            $buffer .= (string) fread($fh, 1 << 16);
            while (($end = strpos($buffer, self::RT)) !== false) {
                $raw = substr($buffer, 0, $end + 1);
                $buffer = substr($buffer, $end + 1);
                $raw = ltrim($raw, "\r\n ");
                if (strlen($raw) > 25) {
                    yield self::iso($raw);
                }
            }
        }
        fclose($fh);
        // A last record without its terminator, or a file that is not MARC at all.
        $buffer = trim($buffer, "\r\n \x1A");
        if (strlen($buffer) > 25) {
            yield self::iso($buffer);
        }
    }

    /** One ISO 2709 record. Lengths in the directory are bytes. */
    public static function iso(string $raw): MarcRecord
    {
        $leader = substr($raw, 0, 24);
        $base = (int) substr($leader, 12, 5);
        if ($base < 25 || $base > strlen($raw)) {
            throw new RuntimeException('سجل MARC تالف (عنوان البيانات غير صحيح).');
        }
        $directory = substr($raw, 24, $base - 25);
        $record = new MarcRecord(self::text($leader));
        for ($i = 0; $i + 12 <= strlen($directory); $i += 12) {
            $tag = substr($directory, $i, 3);
            $len = (int) substr($directory, $i + 3, 4);
            $start = (int) substr($directory, $i + 7, 5);
            $data = rtrim(substr($raw, $base + $start, $len), self::FT.self::RT);
            $record->fields[] = self::parseField($tag, $data);
        }

        return $record;
    }

    private static function parseField(string $tag, string $data): array
    {
        if (MarcRecord::isControl($tag)) {
            return ['tag' => $tag, 'data' => self::text($data)];
        }
        $parts = explode(self::SD, $data);
        $ind = array_shift($parts);
        $subfields = [];
        foreach ($parts as $p) {
            if ($p !== '') {
                $subfields[] = [substr($p, 0, 1), self::text(substr($p, 1))];
            }
        }

        return ['tag' => $tag, 'ind1' => substr($ind.'  ', 0, 1), 'ind2' => substr($ind.'  ', 1, 1), 'subfields' => $subfields];
    }

    /**
     * Text in UTF-8. Records from older Arabic systems (CDS/ISIS, WinISIS) are often in
     * Windows-1256; anything that is not valid UTF-8 is read as that.
     */
    public static function text(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
            return class_exists(\Normalizer::class) && ! \Normalizer::isNormalized($s) ? (\Normalizer::normalize($s) ?: $s) : $s;
        }
        $converted = @iconv('CP1256', 'UTF-8//IGNORE', $s);

        return $converted !== false ? $converted : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }

    /** @return Generator<int, MarcRecord> */
    public static function xmlFile(string $path): Generator
    {
        $xml = new XMLReader;
        if (! $xml->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('ملف MARCXML غير صالح.');
        }
        while (@$xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'record') {
                $node = $xml->expand();
                if ($node) {
                    $record = self::xmlNode($node);
                    if ($record->fields) {
                        yield $record;
                    }
                }
                $xml->next();
            }
        }
        $xml->close();
    }

    /** @return list<MarcRecord> MARCXML records in a string (an SRU answer, a stored record). */
    public static function xmlString(string $xml): array
    {
        $doc = new \DOMDocument;
        if (trim($xml) === '' || ! @$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            return [];
        }
        $out = [];
        foreach ($doc->getElementsByTagNameNS('*', 'record') as $node) {
            // SRU wraps each MARC record in its own <record>: only the MARC ones have a leader or fields.
            $record = self::xmlNode($node);
            if ($record->fields) {
                $out[] = $record;
            }
        }

        return $out;
    }

    private static function xmlNode(\DOMNode $node): MarcRecord
    {
        $record = new MarcRecord;
        foreach ($node->childNodes as $child) {
            if (! $child instanceof \DOMElement) {
                continue;
            }
            $name = $child->localName;
            if ($name === 'leader') {
                $record->leader = str_pad(substr(trim($child->textContent), 0, 24), 24);
            } elseif ($name === 'controlfield') {
                $record->fields[] = ['tag' => $child->getAttribute('tag'), 'data' => $child->textContent];
            } elseif ($name === 'datafield') {
                $subs = [];
                foreach ($child->childNodes as $s) {
                    if ($s instanceof \DOMElement && $s->localName === 'subfield') {
                        $subs[] = [$s->getAttribute('code'), $s->textContent];
                    }
                }
                $record->fields[] = ['tag' => $child->getAttribute('tag'), 'ind1' => substr($child->getAttribute('ind1').' ', 0, 1),
                    'ind2' => substr($child->getAttribute('ind2').' ', 0, 1), 'subfields' => $subs];
            }
        }

        return $record;
    }
}
