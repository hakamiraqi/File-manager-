<?php

namespace App\Services\Covers;

use App\Models\File;
use Illuminate\Support\Str;

/**
 * Puts a cover on a file, or takes it off (2026-11-12). The cover becomes the file's picture
 * everywhere (lists, the library, the app); the picture made from the file is kept aside.
 */
class CoverStore
{
    public static function apply(File $file, string $jpeg, string $source, string $status, ?string $isbn = null): void
    {
        $disk = $file->disk();
        $path = "{$file->user_id}/thumbnails/cover-".Str::uuid().'.jpg';
        $disk->put($path, $jpeg);

        $hadCover = (bool) $file->cover_source;
        $old = $file->thumbnail_path;
        $file->forceFill([
            // The file's own picture is kept the first time only.
            'auto_thumbnail_path' => $hadCover ? $file->auto_thumbnail_path : $old,
            'thumbnail_path' => $path,
            'cover_source' => $source,
            'cover_status' => $status,
            'cover_isbn' => $isbn ?? $file->cover_isbn,
            'cover_checked_at' => now(),
        ])->save();
        if ($hadCover && $old && $old !== $path) {
            $disk->delete($old);
        }
    }

    /** Back to the file's own picture; [$status] 'removed' keeps the automatic search away. */
    public static function remove(File $file, string $status = 'removed'): void
    {
        if ($file->cover_source && $file->thumbnail_path) {
            $file->disk()->delete($file->thumbnail_path);
        }
        $file->forceFill([
            'thumbnail_path' => $file->cover_source ? $file->auto_thumbnail_path : $file->thumbnail_path,
            'auto_thumbnail_path' => null,
            'cover_source' => null,
            'cover_status' => $status,
            'cover_checked_at' => now(),
        ])->save();
    }
}
