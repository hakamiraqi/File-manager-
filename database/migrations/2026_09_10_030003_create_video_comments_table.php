<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('video_comments')->cascadeOnDelete();
            $table->text('body');
            $table->unsignedInteger('timestamp_seconds')->nullable();
            $table->timestamps();

            $table->index('file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_comments');
    }
};
