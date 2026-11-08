<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Files and folders a user saved (like Instagram's bookmark). */
    public function up(): void
    {
        Schema::create('saved_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('savable');
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'savable_type', 'savable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_items');
    }
};
