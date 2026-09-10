<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('timestamp_seconds');
            $table->text('question');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index('file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_questions');
    }
};
