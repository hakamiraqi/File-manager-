<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Was a two-value enum (admin/user); widened to a plain string so
            // editor/contributor can be added without a destructive column swap.
            $table->string('role')->default('user')->change();
            $table->string('password')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_master_admin')->default(false)->after('role');
            $table->boolean('can_manage_appearance')->default(false)->after('is_master_admin');
            $table->boolean('can_manage_users')->default(false)->after('can_manage_appearance');
            $table->boolean('can_manage_translations')->default(false)->after('can_manage_users');
            $table->boolean('can_view_statistics')->default(false)->after('can_manage_translations');
            $table->boolean('can_manage_settings')->default(false)->after('can_view_statistics');
        });

        // The very first admin created by the seeder is the master admin and
        // always carries every capability, matching the rest of this migration.
        DB::table('users')->where('role', 'admin')->orderBy('id')->limit(1)->update([
            'is_master_admin' => true,
            'can_manage_appearance' => true,
            'can_manage_users' => true,
            'can_manage_translations' => true,
            'can_view_statistics' => true,
            'can_manage_settings' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_master_admin',
                'can_manage_appearance',
                'can_manage_users',
                'can_manage_translations',
                'can_view_statistics',
                'can_manage_settings',
            ]);
        });
    }
};
