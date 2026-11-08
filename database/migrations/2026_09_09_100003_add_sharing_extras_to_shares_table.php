<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->string('short_code', 12)->nullable()->unique()->after('token');
            $table->boolean('one_time_download')->default(false)->after('max_downloads');
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->dropColumn(['short_code', 'one_time_download', 'revoked_at']);
        });
    }
};
