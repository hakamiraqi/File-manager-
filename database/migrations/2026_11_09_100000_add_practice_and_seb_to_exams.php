<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Practice exams (check each answer at once) and exams that must be taken in Safe Exam Browser.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->boolean('is_practice')->default(false)->after('is_published');
            $table->boolean('seb_required')->default(false)->after('is_practice');
            $table->text('seb_keys')->nullable()->after('seb_required');   // Config Keys / Browser Exam Keys, one per line
        });
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->timestamp('checked_at')->nullable();   // practice: the answer was checked and is final
        });
    }

    public function down(): void
    {
        Schema::table('exam_answers', fn (Blueprint $table) => $table->dropColumn('checked_at'));
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn(['is_practice', 'seb_required', 'seb_keys']));
    }
};
