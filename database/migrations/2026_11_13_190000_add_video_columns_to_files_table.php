<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The original picture height (the «الأصلية» quality) and when the lower qualities were made. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->unsignedSmallInteger('video_height')->nullable()->after('auto_thumbnail_path');
            $table->timestamp('video_checked_at')->nullable()->after('video_height');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['video_height', 'video_checked_at']);
        });
    }
};
