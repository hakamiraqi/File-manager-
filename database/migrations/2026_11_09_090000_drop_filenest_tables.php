<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The exam system started as a copy of FileNest. Its file, library, sharing and video
// migrations were removed (2026-11-09); this drops what they had left in databases that
// already ran them, and forgets those migrations. A new database never had them.
return new class extends Migration
{
    private const TABLES = [
        'playlist_items', 'playlists', 'video_comments', 'video_question_answers', 'video_question_choices',
        'video_questions', 'video_qualities', 'subtitles', 'share_emails', 'shares', 'saved_items',
        'file_upload_sessions', 'ocr_load_samples', 'files', 'folder_user',
        'library_notices', 'library_holds', 'library_loans', 'library_members', 'book_copies', 'books',
        'library_shelves', 'library_sections', 'library_holidays', 'folders',
        'contact_messages', 'role_permissions',
    ];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        foreach (['users' => 'is_librarian', 'activity_logs' => 'downloads_hidden_at'] as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }

        $kept = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'));
        DB::table('migrations')->whereNotIn('migration', $kept)->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the exam system has no use for these tables.
    }
};
