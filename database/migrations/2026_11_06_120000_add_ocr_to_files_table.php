<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Text recognition (OCR) for scanned images and PDFs (2026-11-06, by request): each file's place
 * in the off-hours queue, and a minute-by-minute log of the computer's load while it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // null = not a candidate yet; pending, processing, done, failed, skipped.
            $table->string('ocr_status', 16)->nullable()->index();
            $table->smallInteger('ocr_priority')->default(0);
            $table->unsignedTinyInteger('ocr_attempts')->default(0);
            $table->string('ocr_error', 500)->nullable();
            $table->unsignedInteger('ocr_seconds')->nullable();
            $table->unsignedInteger('ocr_pages')->nullable();
            $table->timestamp('ocr_done_at')->nullable();
        });

        Schema::create('ocr_load_samples', function (Blueprint $table) {
            $table->id();
            $table->float('cpu')->nullable();
            $table->unsignedInteger('ram_free_mb')->nullable();
            $table->float('disk_free_gb')->nullable();
            $table->unsignedSmallInteger('active_users')->default(0);
            // running, idle (nothing waiting), outside_hours, busy (load too high), disabled, no_engine
            $table->string('state', 20);
            $table->string('reason')->nullable();
            $table->unsignedSmallInteger('processed')->default(0);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_load_samples');
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['ocr_status']);
            $table->dropColumn(['ocr_status', 'ocr_priority', 'ocr_attempts', 'ocr_error', 'ocr_seconds', 'ocr_pages', 'ocr_done_at']);
        });
    }
};
