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

    public function supports(string $extension): bool
    {
        $extension = strtolower($extension);

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
            $text = match (true) {
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

        $text = mb_scrub(preg_replace('/\s+/u', ' ', $text) ?? $text, 'UTF-8');
        $text = trim($text);

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
        $parser = new PdfParser;
        $document = $parser->parseFile($path);

        return $document->getText();
    }

    private function extractDocx(string $path): ?string
    {
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
}
