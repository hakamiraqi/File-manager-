<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A picture for every question (any kind) and for every choice, item or label (2026-11-09).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_questions', fn (Blueprint $table) => $table->string('image', 80)->nullable());
        Schema::table('exam_choices', fn (Blueprint $table) => $table->string('image', 80)->nullable());
    }

    public function down(): void
    {
        Schema::table('exam_questions', fn (Blueprint $table) => $table->dropColumn('image'));
        Schema::table('exam_choices', fn (Blueprint $table) => $table->dropColumn('image'));
    }
};
