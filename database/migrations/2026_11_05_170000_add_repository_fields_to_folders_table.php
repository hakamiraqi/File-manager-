<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Thesis fields that match the Iraqi Digital Repository (iqdr.iq) record. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->string('university')->nullable()->after('degree');
            $table->string('college')->nullable()->after('university');
            $table->string('department')->nullable()->after('college');
            $table->string('subject')->nullable()->after('department');
            $table->string('keywords', 1000)->nullable()->after('subject');
            $table->text('abstract')->nullable()->after('keywords');
            $table->date('defense_date')->nullable()->after('abstract');
            $table->string('language', 10)->nullable()->after('defense_date');
            $table->unsignedInteger('pages')->nullable()->after('language');
            $table->string('iqdr_receipt', 100)->nullable()->after('pages');
            $table->index(['folder_type', 'degree']);
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropIndex(['folder_type', 'degree']);
            $table->dropColumn(['university', 'college', 'department', 'subject', 'keywords', 'abstract', 'defense_date', 'language', 'pages', 'iqdr_receipt']);
        });
    }
};
