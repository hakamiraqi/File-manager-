<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DOI (2026-11-15): research articles and e-books by their DOI, with the journal they appeared in. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $t) {
            $t->string('doi', 255)->nullable()->index();
            $t->string('journal', 255)->nullable();
            $t->string('volume', 20)->nullable();
            $t->string('issue', 20)->nullable();
            $t->string('page_range', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $t) {
            $t->dropIndex(['doi']);
            $t->dropColumn(['doi', 'journal', 'volume', 'issue', 'page_range']);
        });
    }
};
