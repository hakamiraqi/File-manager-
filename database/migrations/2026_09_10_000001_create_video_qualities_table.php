<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_qualities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('height');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index('file_id');
            $table->unique(['file_id', 'height']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_qualities');
    }
};
