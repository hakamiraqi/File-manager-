<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Library, step 1: the catalogue. A book is the title; a copy is the physical item that is
 * lent, with its own barcode and (optional) RFID tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_librarian')->default(false)->after('role');
        });

        Schema::create('library_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('library_sections')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('cover_path')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->nullable()->constrained('library_sections')->nullOnDelete();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('authors')->nullable();
            $table->string('isbn', 20)->nullable()->index();
            $table->string('publisher')->nullable();
            $table->string('year', 10)->nullable();
            $table->string('language', 20)->nullable();
            $table->string('edition', 50)->nullable();
            $table->unsignedInteger('pages')->nullable();
            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->boolean('reference_only')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('title');
        });

        Schema::create('book_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 64)->unique();
            $table->string('rfid_tag', 128)->nullable()->unique();
            $table->string('shelf')->nullable();
            $table->string('status', 20)->default('available')->index();
            $table->string('condition', 20)->default('good');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_copies');
        Schema::dropIfExists('books');
        Schema::dropIfExists('library_sections');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_librarian'));
    }
};
