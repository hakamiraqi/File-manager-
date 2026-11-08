<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Setting::setMany([
            'site_name' => 'نظام الامتحانات',
            'registration_enabled' => '1',
            'registration_role' => 'user',
            'default_locale' => 'ar',
            'active_skin' => 'indigo',
            'custom_css' => '',
            'default_quota_mb' => (string) config('filenest.default_quota_mb'),
            'max_upload_mb' => (string) config('filenest.max_upload_mb'),
            'extension_filter_mode' => 'reject',
            'extension_list' => '',
            'max_zip_files' => '2000',
            'enable_pretty_links' => '0',
            'global_search' => '1',
            'guest_access_enabled' => '0',
            'ip_filter_mode' => 'none',
        ]);

        if (! User::where('email', 'admin@exams.test')->exists()) {
            User::create([
                'name' => 'مدير النظام',
                'email' => 'admin@exams.test',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_master_admin' => true,
                'can_manage_appearance' => true,
                'can_manage_users' => true,
                'can_manage_translations' => true,
                'can_view_statistics' => true,
                'can_manage_settings' => true,
                'email_verified_at' => now(),
            ]);
        }

        $this->call(RolePermissionSeeder::class);
    }
}
