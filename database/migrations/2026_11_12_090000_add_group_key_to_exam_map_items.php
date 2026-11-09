<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// «مجموعة» on the halls map: computers grouped together are chosen and moved as one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_map_items', function (Blueprint $table) {
            $table->string('group_key', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_map_items', fn (Blueprint $table) => $table->dropColumn('group_key'));
    }
};
