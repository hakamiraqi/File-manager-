<?php

use App\Http\Controllers\Library\BookController;
use App\Http\Controllers\Library\CatalogController;
use App\Http\Controllers\Library\CounterController;
use App\Http\Controllers\Library\HoldController;
use App\Http\Controllers\Library\LoanController;
use App\Http\Controllers\Library\MemberController;
use App\Http\Controllers\Library\SettingsController;
use App\Http\Controllers\Library\ImportController;
use App\Http\Controllers\Library\RfidController;
use App\Http\Controllers\Library\SectionController;
use Illuminate\Support\Facades\Route;

// Covers are not private (the public catalogue shows them too).
Route::get('/library/cover/{type}/{id}', [CatalogController::class, 'cover'])
    ->whereIn('type', ['book', 'section'])->whereNumber('id')->name('library.cover');

Route::middleware(['auth', 'verified'])->prefix('library')->name('library.')->group(function () {
    Route::get('/', [CatalogController::class, 'index'])->name('index');
    Route::get('/books/{book}', [CatalogController::class, 'show'])->name('books.show');
    Route::get('/my-loans', [LoanController::class, 'mine'])->name('my-loans');
    Route::post('/loans/{loan}/renew', [LoanController::class, 'renew'])->name('loans.renew');
    Route::post('/books/{book}/hold', [HoldController::class, 'store'])->name('holds.store');
    Route::delete('/holds/{hold}', [HoldController::class, 'destroy'])->name('holds.destroy');
    Route::post('/notices/read', [LoanController::class, 'readNotices'])->name('notices.read');

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

        Route::get('/counter', [CounterController::class, 'page'])->name('counter');
        Route::post('/counter/scan', [CounterController::class, 'scan'])->name('counter.scan');
        Route::get('/counter/members', [CounterController::class, 'members'])->name('counter.members');
        Route::post('/counter/member', [CounterController::class, 'member'])->name('counter.member');

        Route::get('/members', [MemberController::class, 'index'])->name('members');
        Route::post('/members', [MemberController::class, 'store'])->name('members.store');
        Route::put('/members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('/members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');
        Route::post('/members/import', [MemberController::class, 'import'])->name('members.import');
        Route::get('/members/cards', [MemberController::class, 'cards'])->name('members.cards');

        Route::get('/loans', [LoanController::class, 'index'])->name('loans');
        Route::post('/loans/{loan}/pay', [LoanController::class, 'payFine'])->name('loans.pay');
        Route::post('/loans/{loan}/waive', [LoanController::class, 'waiveFine'])->name('loans.waive');

        Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/holidays', [SettingsController::class, 'addHoliday'])->name('holidays.store');
        Route::delete('/holidays/{holiday}', [SettingsController::class, 'removeHoliday'])->name('holidays.destroy');

        Route::get('/rfid', [RfidController::class, 'page'])->name('rfid');
        Route::get('/rfid/untagged', [RfidController::class, 'untagged'])->name('rfid.untagged');
        Route::post('/rfid/lookup', [RfidController::class, 'lookup'])->name('rfid.lookup');
        Route::post('/rfid/link', [RfidController::class, 'link'])->name('rfid.link');
        Route::delete('/rfid/{copy}', [RfidController::class, 'unlink'])->name('rfid.unlink');
        Route::post('/rfid/inventory', [RfidController::class, 'inventory'])->name('rfid.inventory');
        Route::post('/rfid/mark', [RfidController::class, 'mark'])->name('rfid.mark');

        Route::get('/import/template', [ImportController::class, 'template'])->name('import.template');
        Route::post('/import', [ImportController::class, 'store'])->name('import');
    });
});
