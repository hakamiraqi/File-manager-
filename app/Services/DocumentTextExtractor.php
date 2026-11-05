<?php

namespace App\Services;

use App\Models\Setting;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * Extracts plain text from an uploaded document so it can be searched by
 * content, not just filename — the "library catalog" search VFM doesn't
 * offer. Every format is best-effort: a corrupt, encrypted, or unsupported
 * file simply yields no text instead of failing the upload.
 *
 * PowerPoint (.pptx) is parsed by hand (it's just a zip of XML files) rather
 * than via phpoffice/phppresentation, because that package pulls in
 * phpoffice/phpspreadsheet versions with several known-critical CVEs
 * (including an RCE) as a required transitive dependency with no patched
 * version in its compatible range.
 */
class DocumentTextExtractor
{
    private const MAX_TEXT_LENGTH = 2_000_000;

    private const PLAIN_TEXT_EXTENSIONS = ['txt', 'md', 'csv', 'json', 'log'];

    public function __construct(private readonly LibreOfficeConverter $office = new LibreOfficeConverter) {}

    public function supports(string $extension): bool
    {
        $extension = strtolower($extension);

        if ($this->office->available() && $this->office->supports($extension)) {
            return true;
        }

        return in_array($extension, [...self::PLAIN_TEXT_EXTENSIONS, 'pdf', 'docx', 'doc', 'pptx'], true);
    }

    public function isEnabled(): bool
    {
        return (bool) Setting::get('content_indexing_enabled', true);
    }

    public function maxBytes(): int
    {
        return (int) Setting::get('content_indexing_max_mb', 25) * 1024 * 1024;
    }

    /**
     * @return string|null Extracted text, or null when nothing could be extracted.
     */
    public function extract(string $absolutePath, string $extension): ?string
    {
        $extension = strtolower($extension);

        try {
            // LibreOffice first when installed: it reads Word/PowerPoint/Excel the way Office does
            // (tables, text boxes, Arabic), which PhpWord often gets wrong.
            $text = null;
            if ($this->office->available() && $this->office->supports($extension)) {
                $text = $this->office->toText($absolutePath, $extension);
            }
            $text ??= match (true) {
                in_array($extension, self::PLAIN_TEXT_EXTENSIONS, true) => $this->extractPlainText($absolutePath),
                $extension === 'pdf' => $this->extractPdf($absolutePath),
                $extension === 'docx' => $this->extractDocx($absolutePath),
                $extension === 'doc' => $this->extractLegacyDoc($absolutePath),
                $extension === 'pptx' => $this->extractPptx($absolutePath),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        if ($text === null) {
            return null;
        }

        // Keep paragraph breaks (the preview shows this text); only tidy spaces and blank lines.
        $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim(mb_scrub($text, 'UTF-8'));

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::MAX_TEXT_LENGTH);
    }

    private function extractPlainText(string $path): ?string
    {
        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }

    private function extractPdf(string $path): ?string
    {
        $text = LibreOfficeConverter::pdfToText($path);
        if ($text !== null && trim($text) !== '') {
            return $text;
        }

        $parser = new PdfParser;
        $document = $parser->parseFile($path);

        return $document->getText();
    }

    private function extractDocx(string $path): ?string
    {
        // The document XML read directly: every paragraph, table cell and text box in order,
        // one paragraph per line. PhpWord's reader drops text boxes, fields and some tables.
        $text = $this->extractDocxXml($path);
        if ($text !== null && trim($text) !== '') {
            return $text;
        }

        $phpWord = WordIOFactory::load($path, 'Word2007');
        $parts = [];

        foreach ($phpWord->getSections() as $section) {
            $parts[] = $this->walkPhpWordElements($section->getElements());
        }

        return implode(' ', array_filter($parts, fn ($p) => $p !== ''));
    }

    private function extractLegacyDoc(string $path): ?string
    {
        // PhpWord's binary .doc reader is best-effort by its own admission;
        // many older/complex .doc files simply won't parse. That's fine —
        // extraction is a bonus, never a requirement for the upload itself.
        $phpWord = WordIOFactory::load($path, 'MsDoc');
        $parts = [];

        foreach ($phpWord->getSections() as $section) {
            $parts[] = $this->walkPhpWordElements($section->getElements());
        }

        return implode(' ', array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * @param  iterable<object>  $elements
     */
    private function walkPhpWordElements(iterable $elements): string
    {
        $parts = [];

        foreach ($elements as $element) {
            if (method_exists($element, 'getRows')) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $parts[] = $this->walkPhpWordElements($cell->getElements());
                    }
                }
            } elseif (method_exists($element, 'getElements')) {
                $parts[] = $this->walkPhpWordElements($element->getElements());
            } elseif (method_exists($element, 'getText')) {
                $text = $element->getText();
                if (is_string($text)) {
                    $parts[] = $text;
                }
            }
        }

        return implode(' ', array_filter($parts, fn ($p) => $p !== ''));
    }

    private function extractPptx(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        $slideFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $slideFiles[(int) $m[1]] = $name;
            }
        }
        ksort($slideFiles);

        $parts = [];
        foreach ($slideFiles as $name) {
            $xml = $zip->getFromName($name);
            if ($xml === false) {
                continue;
            }

            if (preg_match_all('#<a:t>(.*?)</a:t>#s', $xml, $matches)) {
                foreach ($matches[1] as $chunk) {
                    $parts[] = html_entity_decode(strip_tags($chunk), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        }

        $zip->close();

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function extractDocxXml(string $path): ?string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            return null;
        }

        // Paragraph and row ends become line breaks, tabs and cell ends become spaces.
        $xml = preg_replace('#>[\r\n]+\s*<#', '><', $xml);
        $xml = preg_replace('#</w:p>\s*</w:tc>#', "\t", $xml);
        $xml = preg_replace('#</w:p>|</w:tr>|<w:(br|cr)\b[^>]*/>#', "\n", $xml);
        $xml = preg_replace('#<w:tab\b[^>]*/>#', "\t", $xml);
        // Skip deleted (tracked-change) text and field instructions.
        $xml = preg_replace('#<w:delText\b[^>]*>.*?</w:delText>#s', '', $xml);
        $xml = preg_replace('#<w:instrText\b[^>]*>.*?</w:instrText>#s', '', $xml);

        if (! preg_match_all('#<w:t(?:\s[^>]*)?>(.*?)</w:t>|(\n)|(\t)#s', $xml, $m, PREG_SET_ORDER)) {
            return null;
        }
        $out = '';
        foreach ($m as $part) {
            if (isset($part[3]) && $part[3] !== '') {
                $out .= ' ';
            } elseif (isset($part[2]) && $part[2] !== '') {
                $out .= "\n";
            } else {
                $out .= html_entity_decode($part[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        return $out;
    }
}
