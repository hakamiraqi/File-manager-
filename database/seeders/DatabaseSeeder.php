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
            'site_name' => 'FileNest',
            'registration_enabled' => '1',
            'default_locale' => 'ar',
            'active_skin' => 'indigo',
            'custom_css' => '',
            'default_quota_mb' => (string) config('filenest.default_quota_mb'),
            'max_upload_mb' => (string) config('filenest.max_upload_mb'),
        ]);

        if (! User::where('email', 'admin@filenest.test')->exists()) {
            User::create([
                'name' => 'مدير النظام',
                'email' => 'admin@filenest.test',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]);
        }
    }
}
