<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_upload_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('chunk_size')->nullable()->after('total_chunks');
        });
    }

    public function down(): void
    {
        Schema::table('file_upload_sessions', function (Blueprint $table) {
            $table->dropColumn('chunk_size');
        });
    }
};
