<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The student's official photo (set by the administration, not the student's own avatar), and a
// second teacher's review of an exam's questions before it can be published.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
        });
        Schema::table('exams', function (Blueprint $table) {
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_status', 20)->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_hash', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewer_id');
            $table->dropColumn(['review_status', 'review_note', 'reviewed_at', 'review_hash']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('photo_path'));
    }
};
