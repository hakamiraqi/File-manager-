<?php

use App\Http\Controllers\Library\BookController;
use App\Http\Controllers\Library\CatalogController;
use App\Http\Controllers\Library\ImportController;
use App\Http\Controllers\Library\SectionController;
use Illuminate\Support\Facades\Route;

// Covers are not private (the public catalogue shows them too).
Route::get('/library/cover/{type}/{id}', [CatalogController::class, 'cover'])
    ->whereIn('type', ['book', 'section'])->whereNumber('id')->name('library.cover');

Route::middleware(['auth', 'verified'])->prefix('library')->name('library.')->group(function () {
    Route::get('/', [CatalogController::class, 'index'])->name('index');
    Route::get('/books/{book}', [CatalogController::class, 'show'])->name('books.show');

    Route::middleware('can:manage-library')->prefix('manage')->name('manage.')->group(function () {
        Route::get('/', [BookController::class, 'index'])->name('books');
        Route::post('/books', [BookController::class, 'store'])->name('books.store');
        Route::put('/books/{book}', [BookController::class, 'update'])->name('books.update');
        Route::delete('/books/{book}', [BookController::class, 'destroy'])->name('books.destroy');
        Route::get('/isbn/{isbn}', [BookController::class, 'isbn'])->name('isbn');
        Route::post('/books/{book}/copies', [BookController::class, 'addCopies'])->name('copies.store');
        Route::put('/copies/{copy}', [BookController::class, 'updateCopy'])->name('copies.update');
        Route::delete('/copies/{copy}', [BookController::class, 'destroyCopy'])->name('copies.destroy');
        Route::get('/labels', [BookController::class, 'labels'])->name('labels');

        Route::get('/sections', [SectionController::class, 'index'])->name('sections');
        Route::post('/sections', [SectionController::class, 'store'])->name('sections.store');
        Route::put('/sections/{section}', [SectionController::class, 'update'])->name('sections.update');
        Route::delete('/sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');

        Route::get('/import/template', [ImportController::class, 'template'])->name('import.template');
        Route::post('/import', [ImportController::class, 'store'])->name('import');
    });
});
