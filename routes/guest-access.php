<?php

use App\Http\Controllers\GuestBrowseController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')->name('guest.')->group(function () {
    Route::get('/', [GuestBrowseController::class, 'index'])->name('index');
    Route::get('/folder/{folder}', [GuestBrowseController::class, 'show'])->name('show');
    Route::get('/files/{file}/download', [GuestBrowseController::class, 'download'])->name('download');
});
