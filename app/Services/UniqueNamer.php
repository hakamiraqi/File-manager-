<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class UniqueNamer
{
    /**
     * Return $desiredName if free within the given scope, otherwise append
     * " (1)", " (2)", ... before the extension until it is free.
     */
    public static function forFile(int $userId, ?int $folderId, string $desiredName, ?int $ignoreId = null): string
    {
        $extension = pathinfo($desiredName, PATHINFO_EXTENSION);
        $base = $extension !== '' ? substr($desiredName, 0, -(strlen($extension) + 1)) : $desiredName;
        $suffix = $extension !== '' ? ".{$extension}" : '';

        return self::resolve('files', $userId, $folderId, $base, $suffix, $ignoreId);
    }

    public static function forFolder(int $userId, ?int $parentId, string $desiredName, ?int $ignoreId = null): string
    {
        return self::resolve('folders', $userId, $parentId, $desiredName, '', $ignoreId, parentColumn: 'parent_id');
    }

    private static function resolve(
        string $table,
        int $userId,
        ?int $scopeId,
        string $base,
        string $suffix,
        ?int $ignoreId,
        string $parentColumn = 'folder_id',
    ): string {
        $candidate = $base.$suffix;
        $attempt = 0;

        while (self::exists($table, $userId, $scopeId, $candidate, $ignoreId, $parentColumn)) {
            $attempt++;
            $candidate = "{$base} ({$attempt}){$suffix}";
        }

        return $candidate;
    }

    private static function exists(string $table, int $userId, ?int $scopeId, string $name, ?int $ignoreId, string $parentColumn): bool
    {
        return DB::table($table)
            ->where('user_id', $userId)
            ->where($parentColumn, $scopeId)
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
