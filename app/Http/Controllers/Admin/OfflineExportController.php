<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DatabaseBackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * «نسخة للسيرفر بدون إنترنت» (2026-11-10, by request): the whole hosted FileNest (the database and
 * every uploaded file) as a few .tar parts that unpack into C:\FileNestBackups, where the offline
 * panel's «استعادة» takes it. Nothing is copied on this server: each part is written straight from
 * the stored files as it is downloaded (a full copy filled the disk once). Parts answer byte ranges,
 * so a download cut off (or stopped by the one-hour request limit) carries on where it stopped.
 * Main admin only.
 */
class OfflineExportController extends Controller
{
    private const PART_BYTES = 6_000_000_000;

    private const FOLDERS = ['filemanager', 'public'];

    private function dir(): string
    {
        return storage_path('app/private/offline-export');
    }

    /** The list of parts; ?fresh=1 takes a new copy of the database first. */
    public function index(Request $request, DatabaseBackupService $backups)
    {
        abort_unless($request->user()->is_master_admin, 403);
        $manifest = $this->manifest($request->boolean('fresh'), $backups);
        $parts = collect($manifest['parts'])->map(fn ($p, $i) => ['n' => $i + 1, 'files' => count($p['entries']), 'gb' => round($p['length'] / 1e9, 2)]);
        $total = round(collect($manifest['parts'])->sum('length') / 1e9, 1);
        $db = basename($manifest['database']);
        $rows = $parts->map(fn ($p) => '<tr><td>'.$p['n'].'</td><td>'.$p['files'].'</td><td dir="ltr">'.$p['gb'].' GB</td><td><a href="'.route('admin.offline-export.part', $p['n']).'">تنزيل الجزء '.$p['n'].'</a></td></tr>')->implode('');
        $when = e($manifest['made']);
        $fresh = route('admin.offline-export', ['fresh' => 1]);

        return response(<<<HTML
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>نسخة للسيرفر بدون إنترنت</title>
<style>body{font-family:system-ui,Tahoma,sans-serif;max-width:760px;margin:2rem auto;padding:0 1rem;line-height:1.8;color:#1f2937}table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #e5e7eb;padding:.5rem;text-align:right}a{color:#0e7490;font-weight:600}.box{background:#f0f9fa;border-radius:12px;padding:1rem 1.25rem}code{background:#f3f4f6;padding:0 .3rem;border-radius:4px}</style></head><body>
<h1>نسخة كاملة للسيرفر بدون إنترنت</h1>
<p>قاعدة البيانات (<code dir="ltr">{$db}</code>، أُخذت {$when}) وكل الملفات المرفوعة: <b dir="ltr">{$total} GB</b> في {$parts->count()} أجزاء.</p>
<table><tr><th>الجزء</th><th>الملفات</th><th>الحجم</th><th></th></tr>{$rows}</table>
<div class="box"><b>الخطوات</b><ol>
<li>نزّل كل الأجزاء. إذا انقطع جزء، اضغط «استئناف» في التنزيلات فيكمل من حيث وقف.</li>
<li>افتح موجه الأوامر (cmd) في مجلد التنزيلات، ولكل جزء: <code dir="ltr">tar -xf FileNest-part-1.tar -C C:\</code> (أو افتحها بـ7-Zip واستخرجها إلى <code dir="ltr">C:\</code>).</li>
<li>يصير عندك <code dir="ltr">C:\FileNestBackups</code> فيه ملف النسخة ومجلد <code>uploads</code>.</li>
<li>لوحة FileNest.exe ← النسخ الاحتياطي ← «استعادة» ← اختر الملف <code dir="ltr">{$db}</code> ← «استبدال كل البيانات».</li>
<li>يحتاج حاسوب السيرفر مساحة فارغة بقدر ضعف الحجم تقريباً (الأجزاء ثم نسخها إلى البرنامج)، وتقدر تحذف الأجزاء بعد فك ضغطها.</li>
</ol></div>
<p><a href="{$fresh}">خذ نسخة جديدة من قاعدة البيانات الآن</a> (للنسخة النهائية؛ الأجزاء تتغير بعدها، فنزّلها كلها من جديد).</p>
</body></html>
HTML);
    }

    /** One part as a .tar, with byte ranges for resuming. */
    public function part(Request $request, int $n, DatabaseBackupService $backups)
    {
        abort_unless($request->user()->is_master_admin, 403);
        $manifest = $this->manifest(false, $backups);
        $part = $manifest['parts'][$n - 1] ?? abort(404);
        $length = $part['length'];
        $etag = '"'.$manifest['id'].'-'.$n.'"';
        [$from, $to, $status] = [0, $length - 1, 200];
        $range = $request->header('Range');
        if ($range && (! $request->header('If-Range') || $request->header('If-Range') === $etag) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $from = max(0, $length - (int) $m[2]);
            } else {
                $from = (int) $m[1];
                $to = $m[2] === '' ? $length - 1 : min((int) $m[2], $length - 1);
            }
            if ($from > $to || $from >= $length) {
                return response('', 416, ['Content-Range' => "bytes */$length"]);
            }
            $status = 206;
        }
        $headers = [
            'Content-Type' => 'application/x-tar',
            'Content-Disposition' => 'attachment; filename="FileNest-part-'.$n.'.tar"',
            'Content-Length' => (string) ($to - $from + 1),
            'Accept-Ranges' => 'bytes',
            'ETag' => $etag,
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes $from-$to/$length";
        }

        return response()->stream(function () use ($part, $from, $to) {
            @set_time_limit(0);
            $this->send($part, $from, $to);
        }, $status, $headers);
    }

    /** Writes bytes [$from, $to] of the part's tar. */
    private function send(array $part, int $from, int $to): void
    {
        $at = 0;
        $out = function (string $bytes) use (&$at, $from, $to) {
            $len = strlen($bytes);
            $a = max($from, $at);
            $b = min($to, $at + $len - 1);
            if ($a <= $b) {
                echo substr($bytes, $a - $at, $b - $a + 1);
                flush();
            }
            $at += $len;
        };
        foreach ($part['entries'] as [$name, $path, $size, $mtime]) {
            $block = 512 + (int) ceil($size / 512) * 512;
            if ($at + $block <= $from) {
                $at += $block; // before the range: skipped without reading
                continue;
            }
            if ($at > $to) {
                return;
            }
            $out($this->header($name, $size, $mtime));
            // The file's bytes: only the slice inside the range is read.
            $dataStart = $at;
            $a = max($from, $dataStart);
            $b = min($to, $dataStart + $size - 1);
            if ($a <= $b && $size > 0) {
                $fh = fopen($path, 'rb');
                fseek($fh, $a - $dataStart);
                $left = $b - $a + 1;
                while ($left > 0 && ! feof($fh)) {
                    $chunk = fread($fh, min(1 << 20, $left));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    flush();
                    $left -= strlen($chunk);
                    if (connection_aborted()) {
                        fclose($fh);

                        return;
                    }
                }
                fclose($fh);
                if ($left > 0) {
                    echo str_repeat("\0", $left); // a file that shrank since the list was made
                }
            }
            $at = $dataStart + $size;
            $pad = (512 - $size % 512) % 512;
            if ($pad) {
                $out(str_repeat("\0", $pad));
            }
        }
        $out(str_repeat("\0", 1024));
    }

    /** A ustar header; long names use the prefix field (paths here are short: folder/user/uuid). */
    private function header(string $name, int $size, int $mtime): string
    {
        $prefix = '';
        if (strlen($name) > 100) {
            $cut = strrpos(substr($name, 0, 156), '/');
            $prefix = substr($name, 0, $cut);
            $name = substr($name, $cut + 1);
        }
        $h = str_pad($name, 100, "\0")
            .str_pad('0000644', 7, '0', STR_PAD_LEFT)."\0"
            ."0000000\0"."0000000\0"
            .str_pad(decoct($size), 11, '0', STR_PAD_LEFT)."\0"
            .str_pad(decoct($mtime), 11, '0', STR_PAD_LEFT)."\0"
            .'        '
            .'0'
            .str_repeat("\0", 100)
            ."ustar\0".'00'
            .str_pad('', 32, "\0").str_pad('', 32, "\0")
            .str_repeat("\0", 16)
            .str_pad($prefix, 155, "\0")
            .str_repeat("\0", 12);
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($h[$i]);
        }

        return substr_replace($h, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);
    }

    /**
     * The database copy and the list of files in parts, kept beside it so every part (and every
     * resumed range) is cut the same way until a new copy is asked for.
     */
    private function manifest(bool $fresh, DatabaseBackupService $backups): array
    {
        $dir = $this->dir();
        $file = $dir.'/manifest.json';
        if (! $fresh && is_file($file) && ($m = json_decode(file_get_contents($file), true)) && is_file($m['database'] ?? '')) {
            return $m;
        }
        File::ensureDirectoryExists($dir);
        foreach (glob($dir.'/*.zip') ?: [] as $old) {
            @unlink($old);
        }
        $db = $backups->createDatabaseZip($dir);
        $readme = $dir.'/README.txt';
        file_put_contents($readme, "نسخة FileNest للسيرفر بدون إنترنت.\r\nلوحة FileNest.exe ← النسخ الاحتياطي ← «استعادة» ← اختر الملف ".basename($db)." ← «استبدال كل البيانات».\r\n");
        $entries = [
            ['FileNestBackups/README.txt', $readme, filesize($readme), filemtime($readme)],
            ['FileNestBackups/'.basename($db), $db, filesize($db), filemtime($db)],
        ];
        foreach (self::FOLDERS as $folder) {
            $root = storage_path('app/'.$folder);
            if (! is_dir($root)) {
                continue;
            }
            foreach (File::allFiles($root, true) as $f) {
                if ($f->getFilename() === '.gitignore' || str_contains($f->getRelativePathname(), 'backup-exports')) {
                    continue;
                }
                $entries[] = ['FileNestBackups/uploads/'.$folder.'/'.str_replace('\\', '/', $f->getRelativePathname()), $f->getPathname(), $f->getSize(), $f->getMTime()];
            }
        }
        $parts = [];
        $current = ['entries' => [], 'length' => 1024];
        foreach ($entries as $e) {
            $block = 512 + (int) ceil($e[2] / 512) * 512;
            if ($current['entries'] && $current['length'] + $block > self::PART_BYTES) {
                $parts[] = $current;
                $current = ['entries' => [], 'length' => 1024];
            }
            $current['entries'][] = $e;
            $current['length'] += $block;
        }
        $parts[] = $current;
        $m = ['id' => substr(sha1(json_encode($entries)), 0, 16), 'made' => now()->timezone('Asia/Baghdad')->format('Y-m-d H:i'), 'database' => $db, 'parts' => $parts];
        file_put_contents($file, json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $m;
    }
}
