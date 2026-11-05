<?php

namespace App\Services;

use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Converts office documents with LibreOffice (soffice, headless) when it is installed: to PDF for
 * a faithful preview (layout, tables, Arabic shaping) and to plain text for content search.
 * Like ffmpeg, it is optional: without `soffice` on PATH everything here reports "unavailable"
 * and callers fall back to the pure-PHP readers.
 */
class LibreOfficeConverter
{
    /** Formats LibreOffice opens that browsers can't show by themselves. */
    public const DOCUMENT_EXTENSIONS = ['doc', 'docx', 'odt', 'rtf', 'ppt', 'pptx', 'odp', 'xls', 'xlsx', 'ods'];

    private const WRITER = ['doc', 'docx', 'odt', 'rtf'];

    public function available(): bool
    {
        static $cached = null;

        return $cached ??= (bool) \Illuminate\Support\Facades\Cache::remember('filenest.soffice', 600, fn () => $this->binary() !== null);
    }

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), self::DOCUMENT_EXTENSIONS, true);
    }

    /** Converts $source to PDF at $target. Returns false when it could not. */
    public function toPdf(string $source, string $target, string $extension): bool
    {
        $out = $this->convert($source, $extension, 'pdf');
        if ($out === null) {
            return false;
        }
        Fs::ensureDirectoryExists(dirname($target));
        Fs::move($out, $target);
        Fs::deleteDirectory(dirname($out));

        return true;
    }

    /** Plain text of the document, keeping paragraph breaks; null when it could not. */
    public function toText(string $source, string $extension): ?string
    {
        $extension = strtolower($extension);

        if (in_array($extension, self::WRITER, true)) {
            $out = $this->convert($source, $extension, 'txt:Text (encoded):UTF8');
            if ($out === null) {
                return null;
            }
            $text = file_get_contents($out);
            Fs::deleteDirectory(dirname($out));

            return $text === false ? null : preg_replace('/^\xEF\xBB\xBF/', '', $text);
        }

        // Slides and sheets: through PDF, read back with pdftotext when present.
        $out = $this->convert($source, $extension, 'pdf');
        if ($out === null) {
            return null;
        }
        $text = self::pdfToText($out);
        Fs::deleteDirectory(dirname($out));

        return $text;
    }

    /** pdftotext (poppler) reads Arabic and layout better than the PHP parser; null if it is missing or fails. */
    public static function pdfToText(string $pdf): ?string
    {
        if (! Process::run(['which', 'pdftotext'])->successful()) {
            return null;
        }
        $result = Process::timeout(120)->run(['pdftotext', '-enc', 'UTF-8', $pdf, '-']);

        return $result->successful() ? $result->output() : null;
    }

    private function binary(): ?string
    {
        foreach (['soffice', 'libreoffice'] as $name) {
            $result = Process::run(['which', $name]);
            if ($result->successful() && trim($result->output()) !== '') {
                return trim($result->output());
            }
        }

        return null;
    }

    /** Runs one conversion in its own temp folder and profile (safe to run in parallel). */
    private function convert(string $source, string $extension, string $filter): ?string
    {
        $bin = $this->binary();
        if ($bin === null || ! is_file($source)) {
            return null;
        }

        $work = storage_path('app/tmp/lo-'.Str::random(16));
        Fs::ensureDirectoryExists($work.'/out');
        // LibreOffice picks the import filter from the extension, so give the copy its real one.
        $input = $work.'/input.'.strtolower($extension);
        copy($source, $input);

        $result = Process::timeout(180)
            ->env(['HOME' => $work])
            ->run([
                $bin, '--headless', '--norestore', '--nologo', '--nodefault',
                '-env:UserInstallation=file://'.$work.'/profile',
                '--convert-to', $filter, '--outdir', $work.'/out', $input,
            ]);

        @unlink($input);
        Fs::deleteDirectory($work.'/profile');

        $ext = explode(':', $filter)[0];
        $out = $work.'/out/input.'.$ext;
        if (! $result->successful() || ! is_file($out) || filesize($out) === 0) {
            Fs::deleteDirectory($work);

            return null;
        }

        return $out;
    }
}
