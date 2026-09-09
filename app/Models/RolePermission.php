<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['role', 'action', 'allowed'])]
class RolePermission extends Model
{
    public const ROLES = ['guest', 'user', 'editor', 'contributor'];

    public const ACTIONS = [
        'view', 'download', 'share', 'upload', 'delete',
        'rename', 'move', 'copy', 'new_folder', 'delete_folder', 'rename_folder',
    ];

    protected function casts(): array
    {
        return [
            'allowed' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('role_permissions_matrix'));
        static::deleted(fn () => Cache::forget('role_permissions_matrix'));
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public static function matrix(): array
    {
        return Cache::rememberForever('role_permissions_matrix', function () {
            $rows = self::all()->groupBy('role');
            $matrix = [];

            foreach (self::ROLES as $role) {
                $matrix[$role] = [];
                foreach (self::ACTIONS as $action) {
                    $row = ($rows[$role] ?? collect())->firstWhere('action', $action);
                    $matrix[$role][$action] = $row ? (bool) $row->allowed : false;
                }
            }

            return $matrix;
        });
    }

    public static function allows(string $role, string $action): bool
    {
        return self::matrix()[$role][$action] ?? false;
    }
}
