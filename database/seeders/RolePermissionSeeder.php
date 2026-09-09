<?php

namespace Database\Seeders;

use App\Models\RolePermission;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * A sane, permissive-but-safe starting matrix: regular users get full
     * control of their own drive, editors/contributors are progressively
     * more restricted (mirroring VFM's defaults), and guests can only look
     * and download once an admin turns guest access on.
     */
    public function run(): void
    {
        $defaults = [
            'user' => ['view' => true, 'download' => true, 'share' => true, 'upload' => true, 'delete' => true, 'rename' => true, 'move' => true, 'copy' => true, 'new_folder' => true, 'delete_folder' => true, 'rename_folder' => true],
            'editor' => ['view' => true, 'download' => true, 'share' => true, 'upload' => true, 'delete' => false, 'rename' => true, 'move' => true, 'copy' => false, 'new_folder' => true, 'delete_folder' => false, 'rename_folder' => true],
            'contributor' => ['view' => true, 'download' => true, 'share' => true, 'upload' => true, 'delete' => false, 'rename' => false, 'move' => false, 'copy' => false, 'new_folder' => true, 'delete_folder' => false, 'rename_folder' => false],
            'guest' => ['view' => true, 'download' => true, 'share' => false, 'upload' => false, 'delete' => false, 'rename' => false, 'move' => false, 'copy' => false, 'new_folder' => false, 'delete_folder' => false, 'rename_folder' => false],
        ];

        foreach ($defaults as $role => $actions) {
            foreach ($actions as $action => $allowed) {
                RolePermission::firstOrCreate(['role' => $role, 'action' => $action], ['allowed' => $allowed]);
            }
        }
    }
}
