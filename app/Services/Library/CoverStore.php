<?php

namespace App\Services\Library;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Book and section covers, kept on the local disk (served by a route, so they work on the
 * offline server too) and shrunk to at most 600 px wide when GD is available.
 */
class CoverStore
{
    private const MAX_WIDTH = 600;

    public function fromUpload(UploadedFile $file): string
    {
        return $this->store((string) file_get_contents($file->getRealPath()));
    }

    /** Cover from Open Library (only that host is fetched). Null when offline or not found. */
    public function fromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! in_array($host, ['covers.openlibrary.org'], true)) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($url);
        } catch (\Throwable) {
            return null;
        }

        // Open Library answers a 1x1 gif when it has no cover.
        if (! $response->ok() || strlen($response->body()) < 1000) {
            return null;
        }

        return $this->store($response->body());
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    private function store(string $bytes): string
    {
        $path = 'library/covers/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $this->shrink($bytes));

        return $path;
    }

    private function shrink(string $bytes): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            return $bytes;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::MAX_WIDTH / max(1, $w));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, 85);

        return (string) ob_get_clean();
    }
}
