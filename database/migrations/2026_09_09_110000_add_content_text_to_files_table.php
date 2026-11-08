<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Text extracted from the file's contents (PDF/Word/PowerPoint/
            // plain text), so search can find a document by what's written
            // inside it, not just its filename — like a library catalog.
            $table->longText('content_text')->nullable()->after('thumbnail_path');
            $table->timestamp('content_indexed_at')->nullable()->after('content_text');
        });

        // FULLTEXT indexes are a MySQL/InnoDB feature; sqlite (used in tests
        // and local dev) has no equivalent, so the search falls back to a
        // LIKE query there instead of failing this migration.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE files ADD FULLTEXT files_content_fulltext (name, content_text)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE files DROP INDEX files_content_fulltext');
        }

        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['content_text', 'content_indexed_at']);
        });
    }
};
