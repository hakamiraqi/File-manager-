<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lets a user clear their downloads list without touching the audit log. */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->timestamp('downloads_hidden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn('downloads_hidden_at');
        });
    }
};
