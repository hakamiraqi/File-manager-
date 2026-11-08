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

    }
}
