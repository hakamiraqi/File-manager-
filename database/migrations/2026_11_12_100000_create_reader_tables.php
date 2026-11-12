<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The PDF reader (2026-11-12, WPS-style): each reader's own place, bookmarks and notes on a
// file (highlights, pen, text, notes and signatures), and their signature, on every device.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reader_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('last_page')->default(1);
            $table->unsignedInteger('pages')->nullable();
            $table->json('bookmarks')->nullable();
            $table->longText('annotations')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'file_id']);
        });

        Schema::create('reader_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('strokes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reader_signatures');
        Schema::dropIfExists('reader_states');
    }
};
