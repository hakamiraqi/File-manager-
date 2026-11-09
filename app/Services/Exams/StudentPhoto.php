<?php

namespace App\Services\Exams;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A student's official photo, for the invigilator to compare with the face in the hall. Set by
 * the administration (one at a time or a ZIP named by student number or e-mail), kept off the
 * public disk, and shown only to invigilators and exam staff.
 */
class StudentPhoto
{
    public const DIR = 'exam-photos';

    /** Stores a picture (any common format) as a 320 px portrait JPEG. False when it is not a picture. */
    public static function store(User $user, string $bytes): bool
    {
        $jpeg = self::portrait($bytes, 320);
        if ($jpeg === null) {
            return false;
        }
        $path = self::DIR.'/'.$user->id.'-'.Str::random(12).'.jpg';
        Storage::disk('local')->put($path, $jpeg);
        if ($user->photo_path) {
            Storage::disk('local')->delete($user->photo_path);
        }
        $user->forceFill(['photo_path' => $path])->save();

        return true;
    }

    public static function remove(User $user): void
    {
        if ($user->photo_path) {
            Storage::disk('local')->delete($user->photo_path);
            $user->forceFill(['photo_path' => null])->save();
        }
    }

    /** 3:4, centred, the long side at most $width × 4/3. */
    private static function portrait(string $bytes, int $width): ?string
    {
        if (! function_exists('imagecreatefromstring') || strlen($bytes) > 15 * 1024 * 1024) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            return null;
        }
        [$w, $h] = [imagesx($image), imagesy($image)];
        // Crop to 3:4 around the centre.
        if ($w * 4 > $h * 3) {
            $cw = intdiv($h * 3, 4);
            [$sx, $sy, $sw, $sh] = [intdiv($w - $cw, 2), 0, $cw, $h];
        } else {
            $ch = intdiv($w * 4, 3);
            [$sx, $sy, $sw, $sh] = [0, intdiv($h - $ch, 2), $w, $ch];
        }
        $tw = min($width, $sw);
        $th = intdiv($tw * 4, 3);
        $out = imagecreatetruecolor($tw, $th);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $image, 0, 0, $sx, $sy, $tw, $th, $sw, $sh);
        ob_start();
        imagejpeg($out, null, 85);
        $data = ob_get_clean();
        imagedestroy($image);
        imagedestroy($out);

        return $data ?: null;
    }
}
