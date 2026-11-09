<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «خريطة القاعات» (2026-11-09, by request, from FileNest's library map): the building's halls,
 * corridors, doors, stairs and the computers on them, and what each computer last said about
 * itself (when it was last on, its clock, its browser) for the live map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_map_items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('name')->nullable();
            $table->string('kind', 16)->default('pc');
            $table->unsignedTinyInteger('storey')->default(0);
            $table->unsignedBigInteger('section_id')->nullable(); // unused here; the plan engine reads it
            $table->foreignId('computer_id')->nullable()->constrained('exam_computers')->nullOnDelete();
            $table->decimal('x', 7, 2)->default(0);
            $table->decimal('y', 7, 2)->default(0);
            $table->unsignedSmallInteger('rotation')->default(0);
            $table->decimal('width', 6, 2)->default(1.2);
            $table->decimal('depth', 6, 2)->default(0.7);
            $table->unsignedTinyInteger('levels')->default(1);
            $table->boolean('double_sided')->default(false);
            $table->string('color', 9)->nullable();
            $table->json('points')->nullable();
            $table->decimal('height', 4, 2)->nullable();
            $table->boolean('locked')->default(false);
            $table->timestamps();
            $table->index('code');
        });
        Schema::table('exam_computers', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->integer('last_clock_offset')->nullable();
            $table->string('last_agent', 200)->nullable();
            $table->unsignedBigInteger('last_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_computers', fn (Blueprint $table) => $table->dropColumn(['last_seen_at', 'last_clock_offset', 'last_agent', 'last_user_id']));
        Schema::dropIfExists('exam_map_items');
    }
};
