<?php

namespace App\Services;

use App\Jobs\ExtractFileText;
use App\Jobs\GenerateThumbnail;
use App\Jobs\TranscodeVideoQualities;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * Unpacks an uploaded .zip into a new folder (named after the archive) of
 * the user's file manager.
 *
 * Safety:
 *  - zip-slip: entry paths are never used as paths on disk. Every file is
 *    stored under a fresh "{user}/{uuid}.ext" name like any upload; the
 *    entry's path only names folders/files in the database, after "..",
 *    ".", empty and drive parts are dropped.
 *  - zip bombs: the entry count, folder depth, total unpacked size and the
 *    packing ratio are checked before anything is written, and the bytes
 *    actually read are counted while writing (a forged header that claims
 *    a small size is cut off as soon as it writes more than it claimed).
 *  - forbidden types (php, exe, …) and the admin's extension filter apply
 *    to every entry exactly as they do to a normal upload; such entries are
 *    skipped and reported, and symlinks are skipped.
 *  - the user's storage quota is checked against the unpacked size first.
 *
 * Any failure part-way removes everything already unpacked.
 */
class ZipExtractor
{
    /** Names that are operating-system clutter, never user files. */
    private const JUNK = ['.ds_store', 'thumbs.db', 'desktop.ini'];

    /** Bytes 0x80–0xFF of code page 720 (Arabic DOS), which Windows' own zipper and 7-Zip use for Arabic names. */
    private const CP720 = '"\u0080\u0081éâ\u0084à\u0086çêëèïî\u008d\u008e\u008f\u0090ّْô¤ـûùءآأؤ£إئابةتثجحخدذرزسشص«»░▒▓│┤╡╢╖╕╣║╗╝╜╛┐└┴┬├─┼╞╟╚╔╩╦╠═╬╧╨╤╥╙╘╒╓╫╪┘┌█▄▌▐▀ضطظعغفµقكلمنهوىي≡ًٌٍَُِ≈°∙·√ⁿ²■ "';

    public function __construct(
        private readonly StorageQuotaService $quota,
        private readonly ExtensionFilter $extensionFilter,
    ) {}

    public static function isZip(?string $name): bool
    {
        return strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)) === 'zip';
    }

    /**
     * @return array{folder: Folder, files: int, folders: int, skipped: array<int, string>}
     */
    public function extract(string $zipPath, string $archiveName, User $user, ?int $parentFolderId): array
    {
        @set_time_limit(0);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['file' => 'تعذّر فتح الملف المضغوط، قد يكون تالفاً أو ليس بصيغة zip.']);
        }

        try {
            [$entries, $skipped, $total] = $this->plan($zip, (int) filesize($zipPath));
            $this->quota->assertHasSpace($user, $total);

            return $this->unpack($zip, $entries, $skipped, $archiveName, $user, $parentFolderId);
        } finally {
            $zip->close();
        }
    }

    /**
     * Reads the archive's directory and decides what will be unpacked,
     * without writing anything.
     *
     * @return array{0: array<int, array{index: int, dirs: array<int, string>, name: ?string, size: int}>, 1: array<int, string>, 2: int}
     */
    private function plan(ZipArchive $zip, int $archiveBytes): array
    {
        $maxEntries = (int) config('filenest.zip_max_entries', 10000);
        $maxDepth = (int) config('filenest.zip_max_depth', 32);
        $maxTotal = (int) config('filenest.zip_max_extracted_mb', 4096) * 1024 * 1024;
        $maxRatio = (int) config('filenest.zip_max_ratio', 200);

        if ($zip->numFiles === 0) {
            throw ValidationException::withMessages(['file' => 'الملف المضغوط فارغ.']);
        }
        if ($zip->numFiles > $maxEntries) {
            throw ValidationException::withMessages(['file' => "الملف المضغوط يحتوي على أكثر من {$maxEntries} عنصر."]);
        }

        $entries = [];
        $skipped = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
            if ($stat === false) {
                throw ValidationException::withMessages(['file' => 'الملف المضغوط تالف.']);
            }

            $path = $this->decodeName((string) $stat['name']);
            $isDir = str_ends_with(str_replace('\\', '/', $path), '/');
            $parts = $this->safeSegments($path);

            if ($parts === [] || in_array('__MACOSX', $parts, true)) {
                continue;
            }
            if (count($parts) > $maxDepth) {
                throw ValidationException::withMessages(['file' => 'الملف المضغوط يحتوي على مجلدات متداخلة بعمق غير طبيعي.']);
            }

            if ($isDir) {
                $entries[] = ['index' => $i, 'dirs' => $parts, 'name' => null, 'size' => 0];

                continue;
            }

            $name = array_pop($parts);

            if (in_array(strtolower($name), self::JUNK, true) || str_starts_with($name, '._')) {
                continue;
            }

            if ($this->isSymlink($zip, $i)) {
                $skipped[] = $name;

                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($extension, config('filenest.forbidden_extensions'), true) || ! $this->extensionFilter->isAllowed($extension)) {
                $skipped[] = $name;

                continue;
            }

            $size = (int) $stat['size'];
            $compressed = max(1, (int) $stat['comp_size']);

            // One entry that inflates absurdly (the classic 42.zip trick).
            if ($size > 10 * 1024 * 1024 && intdiv($size, $compressed) > $maxRatio) {
                throw ValidationException::withMessages(['file' => 'رُفض الملف المضغوط: نسبة الضغط غير طبيعية (zip bomb).']);
            }

            $total += $size;
            if ($total > $maxTotal) {
                throw ValidationException::withMessages(['file' => 'حجم محتويات الملف المضغوط بعد الاستخراج يتجاوز الحد المسموح ('.intdiv($maxTotal, 1024 * 1024).' ميغابايت).']);
            }

            $entries[] = ['index' => $i, 'dirs' => $parts, 'name' => $name, 'size' => $size];
        }

        if ($total > 100 * 1024 * 1024 && intdiv($total, max(1, $archiveBytes)) > $maxRatio) {
            throw ValidationException::withMessages(['file' => 'رُفض الملف المضغوط: نسبة الضغط غير طبيعية (zip bomb).']);
        }

        return [$entries, $skipped, $total];
    }

    /**
     * @param  array<int, array{index: int, dirs: array<int, string>, name: ?string, size: int}>  $entries
     * @param  array<int, string>  $skipped
     * @return array{folder: Folder, files: int, folders: int, skipped: array<int, string>}
     */
    private function unpack(ZipArchive $zip, array $entries, array $skipped, string $archiveName, User $user, ?int $parentFolderId): array
    {
        $diskName = config('filenest.disk');
        $disk = Storage::disk($diskName);
        $disk->makeDirectory((string) $user->id);
        $maxTotal = (int) config('filenest.zip_max_extracted_mb', 4096) * 1024 * 1024;

        $rootName = trim(pathinfo($archiveName, PATHINFO_FILENAME)) ?: 'zip';

        /** @var array<int, Folder> $createdFolders */
        $createdFolders = [];
        /** @var array<int, File> $createdFiles */
        $createdFiles = [];
        $written = 0;

        try {
            $root = Folder::create([
                'user_id' => $user->id,
                'parent_id' => $parentFolderId,
                'name' => UniqueNamer::forFolder($user->id, $parentFolderId, Str::limit($rootName, 250, '')),
            ]);
            $createdFolders[] = $root;

            // "a/b" (inside the archive) => folder id.
            $folderIds = ['' => $root->id];
            $resolve = function (array $dirs) use (&$folderIds, &$createdFolders, $user): int {
                $key = '';
                foreach ($dirs as $dir) {
                    $parentId = $folderIds[$key];
                    $key = $key === '' ? $dir : "{$key}/{$dir}";
                    if (! isset($folderIds[$key])) {
                        $folder = Folder::create([
                            'user_id' => $user->id,
                            'parent_id' => $parentId,
                            'name' => UniqueNamer::forFolder($user->id, $parentId, $dir),
                        ]);
                        $createdFolders[] = $folder;
                        $folderIds[$key] = $folder->id;
                    }
                }

                return $folderIds[$key];
            };

            foreach ($entries as $entry) {
                $folderId = $resolve($entry['dirs']);
                if ($entry['name'] === null) {
                    continue;
                }

                $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
                $relativePath = "{$user->id}/".Str::uuid().($extension !== '' ? ".{$extension}" : '');
                $destination = $disk->path($relativePath);

                $bytes = $this->copyEntry($zip, $entry['index'], $destination, $entry['size'], $maxTotal - $written);
                $written += $bytes;

                $createdFiles[] = File::create([
                    'user_id' => $user->id,
                    'folder_id' => $folderId,
                    'name' => UniqueNamer::forFile($user->id, $folderId, Str::limit($entry['name'], 250, '')),
                    'disk' => $diskName,
                    'path' => $relativePath,
                    'extension' => $extension !== '' ? $extension : null,
                    'mime_type' => @mime_content_type($destination) ?: 'application/octet-stream',
                    'size' => $bytes,
                ]);
            }
        } catch (Throwable $e) {
            foreach ($createdFiles as $file) {
                $disk->delete($file->path);
                $file->forceDelete();
            }
            if (isset($relativePath) && ! collect($createdFiles)->contains('path', $relativePath)) {
                $disk->delete($relativePath);
            }
            foreach (array_reverse($createdFolders) as $folder) {
                $folder->forceDelete();
            }

            throw $e;
        }

        $this->quota->increment($user, $written);

        foreach ($createdFiles as $file) {
            ExtractFileText::dispatch($file->id);
            GenerateThumbnail::dispatch($file->id);
            if ($file->isVideo()) {
                TranscodeVideoQualities::dispatch($file->id);
            }
        }

        return [
            'folder' => $root,
            'files' => count($createdFiles),
            'folders' => count($createdFolders) - 1,
            'skipped' => $skipped,
        ];
    }

    /**
     * Streams one entry to disk, refusing to write more than the archive
     * claimed for it (or than is left of the overall allowance).
     */
    private function copyEntry(ZipArchive $zip, int $index, string $destination, int $declared, int $allowance): int
    {
        $in = $zip->getStreamIndex($index);
        if ($in === false) {
            throw ValidationException::withMessages(['file' => 'تعذّر قراءة أحد الملفات داخل الملف المضغوط.']);
        }

        $out = fopen($destination, 'wb');
        $written = 0;

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    throw ValidationException::withMessages(['file' => 'الملف المضغوط تالف.']);
                }
                $written += strlen($chunk);
                if ($written > $declared || $written > $allowance) {
                    throw ValidationException::withMessages(['file' => 'رُفض الملف المضغوط: محتواه أكبر مما يعلنه (zip bomb).']);
                }
                fwrite($out, $chunk);
            }
        } catch (Throwable $e) {
            fclose($out);
            @unlink($destination);

            throw $e;
        } finally {
            fclose($in);
        }

        fclose($out);

        return $written;
    }

    /**
     * Splits an entry path into folder/file names that are safe as display
     * names: no "..", ".", empty parts, drive letters or control characters.
     *
     * @return array<int, string>
     */
    private function safeSegments(string $path): array
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            $part = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $part) ?? '');
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/^[A-Za-z]:$/', $part)) {
                continue;
            }
            $parts[] = $part;
        }

        return $parts;
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        return $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000;
    }

    /** Names in archives made without the UTF-8 flag are in the zipper's DOS code page (Arabic: 720). */
    private function decodeName(string $raw): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        static $map = null;
        $map ??= mb_str_split(json_decode(self::CP720));

        $out = '';
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($raw[$i]);
            $out .= $byte < 0x80 ? $raw[$i] : $map[$byte - 0x80];
        }

        return $out;
    }
}
