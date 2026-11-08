<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Classes (groups of students) that exams are given to, the hall's access code and network,
// and invigilators.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // who made it
            $table->string('name');
            $table->string('stage', 100)->nullable();
            $table->string('department')->nullable();
            $table->timestamps();
        });
        Schema::create('exam_group_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['exam_group_id', 'user_id']);
        });
        Schema::create('exam_exam_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_group_id')->constrained()->cascadeOnDelete();
            $table->unique(['exam_id', 'exam_group_id']);
        });
        Schema::table('exams', function (Blueprint $table) {
            $table->string('access_code', 20)->nullable();   // announced in the hall by the invigilator
            $table->text('allowed_ips')->nullable();          // addresses or ranges (CIDR), one per line
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_invigilator')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_invigilator'));
        Schema::table('exams', fn (Blueprint $table) => $table->dropColumn(['access_code', 'allowed_ips']));
        Schema::dropIfExists('exam_exam_group');
        Schema::dropIfExists('exam_group_user');
        Schema::dropIfExists('exam_groups');
    }
};
