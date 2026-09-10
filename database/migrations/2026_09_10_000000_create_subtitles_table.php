<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->string('language', 10);
            $table->string('label', 100);
            $table->string('path');
            $table->timestamps();

            $table->index('file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitles');
    }
};
