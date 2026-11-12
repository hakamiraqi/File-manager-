<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Book covers (2026-11-12): a cover found online (or chosen by hand) becomes the file's picture;
// the picture made from the file itself is kept to come back to.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // null = not looked for yet; found / manual / none / small / removed / error
            $table->string('cover_status', 16)->nullable()->index();
            // openlibrary / google / upload / page
            $table->string('cover_source', 16)->nullable();
            $table->string('cover_isbn', 20)->nullable();
            $table->timestamp('cover_checked_at')->nullable();
            $table->string('auto_thumbnail_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['cover_status']);
            $table->dropColumn(['cover_status', 'cover_source', 'cover_isbn', 'cover_checked_at', 'auto_thumbnail_path']);
        });
    }
};
