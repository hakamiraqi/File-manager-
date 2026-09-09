<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'user'])->default('user')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
            $table->unsignedBigInteger('storage_quota_bytes')->nullable()->after('is_active');
            $table->unsignedBigInteger('storage_used_bytes')->default(0)->after('storage_quota_bytes');
            $table->string('locale', 5)->nullable()->after('storage_used_bytes');
            $table->string('theme', 20)->default('system')->after('locale');
            $table->string('avatar_path')->nullable()->after('theme');
            $table->timestamp('last_login_at')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'is_active',
                'storage_quota_bytes',
                'storage_used_bytes',
                'locale',
                'theme',
                'avatar_path',
                'last_login_at',
            ]);
        });
    }
};
