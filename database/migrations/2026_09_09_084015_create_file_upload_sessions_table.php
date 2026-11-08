<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->uuid('identifier')->unique();
            $table->string('original_name');
            $table->unsignedBigInteger('total_size');
            $table->unsignedInteger('total_chunks');
            $table->json('received_chunks')->nullable();
            $table->string('status', 20)->default('uploading');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_upload_sessions');
    }
};
