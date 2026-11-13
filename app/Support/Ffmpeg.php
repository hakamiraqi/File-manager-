<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Where ffmpeg is (2026-11-13): FFMPEG_BIN, the copy shipped next to the offline Windows
 * server (..\ffmpeg\bin\ffmpeg.exe), or the one on PATH — `which` on Linux, `where` on Windows.
 */
class Ffmpeg
{
    public static function binary(): ?string
    {
        $configured = config('services.video.ffmpeg');
        if ($configured && is_file($configured)) {
            return $configured;
        }
        foreach ([base_path('../ffmpeg/bin/ffmpeg.exe'), base_path('../ffmpeg/ffmpeg.exe')] as $path) {
            if (is_file($path)) {
                return realpath($path) ?: $path;
            }
        }

        return Cache::remember('filenest.ffmpeg.bin', 600, function () {
            $r = Process::run(PHP_OS_FAMILY === 'Windows' ? ['where', 'ffmpeg'] : ['which', 'ffmpeg']);
            $line = trim(strtok($r->output(), "\r\n") ?: '');

            return $r->successful() && $line !== '' ? $line : null;
        }) ?: null;
    }

    public static function available(): bool
    {
        return self::binary() !== null;
    }

    /** The picture height of a video (ffmpeg prints the streams when given no output), or null. */
    public static function height(string $path): ?int
    {
        $bin = self::binary();
        if (! $bin) {
            return null;
        }
        $out = Process::timeout(30)->run([$bin, '-hide_banner', '-i', $path])->errorOutput();
        if (preg_match('/Stream #[^\n]*Video:[^\n]*?\b(\d{2,5})x(\d{2,5})\b/', $out, $m)) {
            $h = (int) $m[2];
            // A phone video shot upright is stored sideways with a rotation flag.
            if (preg_match('/rotat(e|ion)[^\n]*?-?(90|270)/i', $out)) {
                $h = (int) $m[1];
            }

            return $h > 0 ? $h : null;
        }

        return null;
    }
}
