<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Imagick;
use Throwable;

/**
 * Generates a small preview image for a file so the browser can show a
 * real thumbnail instead of a generic file-type icon — matching what
 * Google Drive/Dropbox/Nextcloud do. Every codec/tool is optional and
 * detected at runtime:
 *
 * - Images: GD (bundled with virtually every PHP install).
 * - Video (first frame): the `ffmpeg` binary, if present on PATH.
 * - PDF (first page): Poppler's `pdftoppm` (on the server for OCR), else the
 *   `imagick` PHP extension with its Ghostscript delegate.
 *
 * Whichever tool is missing simply means no thumbnail for that type — the
 * upload itself is never affected, exactly like DocumentTextExtractor.
 */
class ThumbnailGenerator
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'];

    public function isEnabled(): bool
    {
        return (bool) Setting::get('thumbnails_enabled', true);
    }

    public function maxDimension(): int
    {
        return (int) Setting::get('thumbnail_max_px', 400);
    }

    public function supports(string $extension): bool
    {
        $extension = strtolower($extension);

        return in_array($extension, self::IMAGE_EXTENSIONS, true)
            || (in_array($extension, self::VIDEO_EXTENSIONS, true) && $this->ffmpegAvailable())
            || ($extension === 'pdf' && ($this->pdftoppmAvailable() || $this->imagickAvailable()));
    }

    /**
     * @return string|null Raw JPEG bytes of the generated thumbnail, or null.
     */
    public function generate(string $absolutePath, string $extension): ?string
    {
        $extension = strtolower($extension);

        try {
            return match (true) {
                in_array($extension, self::IMAGE_EXTENSIONS, true) => $this->fromImage($absolutePath),
                in_array($extension, self::VIDEO_EXTENSIONS, true) && $this->ffmpegAvailable() => $this->fromVideo($absolutePath),
                $extension === 'pdf' && $this->pdftoppmAvailable() => $this->fromPdfWithPoppler($absolutePath),
                $extension === 'pdf' && $this->imagickAvailable() => $this->fromPdf($absolutePath),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function fromImage(string $path): ?string
    {
        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        $source = match ($info[2]) {
            \IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            \IMAGETYPE_PNG => @imagecreatefrompng($path),
            \IMAGETYPE_GIF => @imagecreatefromgif($path),
            \IMAGETYPE_BMP => @imagecreatefrombmp($path),
            \IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if ($source === false || $source === null) {
            return null;
        }

        $jpeg = $this->resizeAndEncode($source);
        imagedestroy($source);

        return $jpeg;
    }

    private function fromVideo(string $path): ?string
    {
        $tmpFrame = tempnam(sys_get_temp_dir(), 'thumb').'.jpg';

        $result = Process::timeout(30)->run([
            \App\Support\Ffmpeg::binary() ?? 'ffmpeg', '-y', '-ss', '00:00:01', '-i', $path,
            '-frames:v', '1', '-vf', 'scale=640:-1', $tmpFrame,
        ]);

        if (! $result->successful() || ! is_file($tmpFrame)) {
            @unlink($tmpFrame);

            return null;
        }

        $source = @imagecreatefromjpeg($tmpFrame);
        @unlink($tmpFrame);

        if ($source === false) {
            return null;
        }

        $jpeg = $this->resizeAndEncode($source);
        imagedestroy($source);

        return $jpeg;
    }

    /** The first page with pdftoppm (2026-11-11: the server has Poppler, not Imagick). */
    private function fromPdfWithPoppler(string $path): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'pthumb');
        @unlink($base);

        $result = Process::timeout(60)->run([
            'pdftoppm', '-jpeg', '-jpegopt', 'quality=85', '-f', '1', '-l', '1',
            '-scale-to', (string) max(200, $this->maxDimension() * 2), '-singlefile', $path, $base,
        ]);
        $jpg = "{$base}.jpg";

        if (! $result->successful() || ! is_file($jpg)) {
            @unlink($jpg);

            return null;
        }

        $source = @imagecreatefromjpeg($jpg);
        @unlink($jpg);

        if ($source === false) {
            return null;
        }

        $jpeg = $this->resizeAndEncode($source);
        imagedestroy($source);

        return $jpeg;
    }

    private function fromPdf(string $path): ?string
    {
        $imagick = new Imagick;
        $imagick->setResolution(100, 100);
        $imagick->readImage("{$path}[0]");
        $imagick->setImageFormat('jpeg');
        $imagick->setImageBackgroundColor('white');
        $imagick = $imagick->flattenImages();

        $source = @imagecreatefromstring($imagick->getImageBlob());
        $imagick->clear();

        if ($source === false) {
            return null;
        }

        $jpeg = $this->resizeAndEncode($source);
        imagedestroy($source);

        return $jpeg;
    }

    /**
     * @param  \GdImage  $source
     */
    private function resizeAndEncode($source): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $max = $this->maxDimension();

        $scale = min(1, $max / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $white);
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($thumb, null, 82);
        $bytes = ob_get_clean();
        imagedestroy($thumb);

        return $bytes;
    }

    public function ffmpegAvailable(): bool
    {
        return \App\Support\Ffmpeg::available();
    }

    public function pdftoppmAvailable(): bool
    {
        static $cached = null;

        return $cached ??= Process::run(['which', 'pdftoppm'])->successful();
    }

    public function imagickAvailable(): bool
    {
        return extension_loaded('imagick');
    }

    public function randomThumbnailPath(int $userId): string
    {
        return "{$userId}/thumbnails/".Str::uuid().'.jpg';
    }
}
