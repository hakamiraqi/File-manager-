<?php

use App\Http\Controllers\GuestBrowseController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')->name('guest.')->group(function () {
    // The visitors' home: library, theses, public files and «تواصل معنا».
    Route::get('/', [GuestBrowseController::class, 'home'])->name('index');
    Route::get('/files', [GuestBrowseController::class, 'index'])->name('files');
    Route::get('/folder/{folder}', [GuestBrowseController::class, 'show'])->name('show');
    Route::get('/files/{file}/download', [GuestBrowseController::class, 'download'])->name('download');
    Route::get('/files/{file}/thumbnail', [GuestBrowseController::class, 'thumbnail'])->name('thumbnail');
});
