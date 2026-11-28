<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Folder looks (2026-11-28): a theme (a college preset), a logo and a cover picture per folder,
// next to the colour it already had. And the archive: files sorted by the words in their text
// (صادر، وارد، أمر إداري…) with the letter's number and date read out of it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->string('theme', 40)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
        });

        Schema::create('archive_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('keywords');                       // one word or phrase per line
            $table->string('match_mode', 8)->default('any'); // any | all
            $table->string('color', 20)->nullable();
            $table->foreignId('target_folder_id')->nullable()->constrained('folders')->nullOnDelete();
            $table->integer('priority')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('files', function (Blueprint $table) {
            $table->foreignId('archive_rule_id')->nullable()->constrained('archive_rules')->nullOnDelete();
            $table->string('archive_label', 100)->nullable();
            $table->string('archive_tags', 500)->nullable();   // every matching category, «|صادر|أمر إداري|»
            $table->string('archive_ref', 60)->nullable();
            $table->string('archive_subject', 255)->nullable();  // «م/ …» or «الموضوع: …» of a letter
            $table->date('archive_date')->nullable();
            $table->boolean('archive_manual')->default(false);
            $table->timestamp('archive_classified_at')->nullable();
            $table->index(['archive_rule_id', 'archive_date']);
        });

        $now = now();
        $rules = [
            // Order matters only on a tie; the header of a letter weighs more than its body.
            ['وارد', "الوارد\nوارد\nالواردة\nكتابكم المرقم\nإشارة إلى كتابكم\nاشارة الى كتابكم\nتاريخ الورود", '#2563eb', 50],
            ['صادر', "الصادر\nصادر\nالصادرة", '#059669', 50],
            ['أمر إداري', "أمر إداري\nامر اداري\nالأمر الإداري\nأمر جامعي\nبناءً على الصلاحيات المخولة", '#7c3aed', 60],
            ['تعميم', "تعميم\nيعمم\nللتعميم\nنعمم", '#0891b2', 55],
            ['محضر اجتماع', "محضر\nمحضر اجتماع\nاجتمعت اللجنة\nجدول الأعمال", '#ca8a04', 45],
            ['كتاب شكر وتقدير', "شكر وتقدير\nكتاب شكر\nتثمينا لجهودكم\nتثميناً لجهودكم", '#db2777', 40],
            ['إجازة', "إجازة اعتيادية\nاجازة اعتيادية\nإجازة مرضية\nاجازة مرضية\nطلب إجازة\nطلب اجازة", '#ea580c', 40],
            ['طلب', "طلب\nعريضة\nأرجو التفضل\nارجو التفضل\nيرجى الموافقة", '#64748b', 10],
            ['مسودة', "مسودة\nمسوده\nمشروع كتاب\ndraft", '#9ca3af', 30],
            ['مالية', "فاتورة\nوصل قبض\nمستند صرف\nسند صرف\nصك\nمبلغ وقدره", '#16a34a', 35],
        ];
        DB::table('archive_rules')->insert(array_map(fn ($r) => [
            'name' => $r[0], 'keywords' => $r[1], 'match_mode' => 'any', 'color' => $r[2],
            'priority' => $r[3], 'enabled' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $rules));
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['archive_rule_id', 'archive_date']);
            $table->dropConstrainedForeignId('archive_rule_id');
            $table->dropColumn(['archive_label', 'archive_tags', 'archive_ref', 'archive_subject', 'archive_date', 'archive_manual', 'archive_classified_at']);
        });
        Schema::dropIfExists('archive_rules');
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn(['theme', 'logo_path', 'cover_path']);
        });
    }
};
