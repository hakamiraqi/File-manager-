<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The library floor plan (2026-11-06): every bookcase, desk or wall in the reading room, placed
 * in metres on a top-down plan. A copy is on the bookcase whose code matches its «الرف» text
 * ("A3" or "A3-2" for level 2); otherwise on a bookcase that holds the book's section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_shelves', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('name')->nullable();
            $table->string('kind', 16)->default('shelf');
            $table->foreignId('section_id')->nullable()->constrained('library_sections')->nullOnDelete();
            $table->decimal('x', 7, 2)->default(0);
            $table->decimal('y', 7, 2)->default(0);
            $table->unsignedSmallInteger('rotation')->default(0);
            $table->decimal('width', 5, 2)->default(2);
            $table->decimal('depth', 5, 2)->default(0.6);
            $table->unsignedTinyInteger('levels')->default(5);
            $table->boolean('double_sided')->default(false);
            $table->string('color', 9)->nullable();
            $table->timestamps();
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_shelves');
    }
};
