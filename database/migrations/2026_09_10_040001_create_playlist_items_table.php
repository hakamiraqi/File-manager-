<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->index('playlist_id');
            $table->unique(['playlist_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_items');
    }
};
