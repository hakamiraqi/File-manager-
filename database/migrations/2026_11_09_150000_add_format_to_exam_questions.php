<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A question's text is plain (with line breaks) or formatted HTML from the editor (cleaned on save).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_questions', function (Blueprint $table) {
            $table->string('format', 10)->default('text');
        });
    }

    public function down(): void
    {
        Schema::table('exam_questions', fn (Blueprint $table) => $table->dropColumn('format'));
    }
};
