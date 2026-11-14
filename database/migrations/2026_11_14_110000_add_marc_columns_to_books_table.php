<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MARC (2026-11-14): the call number and subjects cataloguers rely on, the record's control
 * number (to recognise a book imported again), and the whole original record, so exporting
 * to Koha loses nothing FileNest has no field for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('call_number', 64)->nullable()->after('edition');
            $table->text('subjects')->nullable()->after('description');
            $table->string('marc_control', 64)->nullable()->index()->after('subjects');
            $table->longText('marc_xml')->nullable()->after('marc_control');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['call_number', 'subjects', 'marc_control', 'marc_xml']);
        });
    }
};
