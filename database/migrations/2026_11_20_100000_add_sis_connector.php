<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «ربط نظام الطلبة» (2026-11-20): a group made from an SIS course section remembers it, and each
// sending of marks to SIS is kept (what was sent, and what the teacher there did with it).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('sis_offering_id')->nullable()->index();
        });
        Schema::create('exam_sis_pushes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->unsignedBigInteger('offering_id');
            $table->string('offering_label')->nullable();
            $table->string('component', 40);
            $table->decimal('scale_to', 8, 2);
            $table->string('reference', 100)->unique();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('status', 20)->default('pending'); // pending | applied | rejected | superseded
            $table->unsignedInteger('sent')->default(0);
            $table->json('unknown')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sis_pushes');
        Schema::table('exam_groups', fn (Blueprint $table) => $table->dropColumn('sis_offering_id'));
    }
};
