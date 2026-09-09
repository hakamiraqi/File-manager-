<?php

use App\Http\Controllers\PublicShareController;
use Illuminate\Support\Facades\Route;

Route::prefix('s')->name('share.public.')->group(function () {
    Route::get('/{token}', [PublicShareController::class, 'show'])->name('show');
    Route::post('/{token}/unlock', [PublicShareController::class, 'unlock'])->name('unlock');
    Route::get('/{token}/download', [PublicShareController::class, 'download'])->name('download');
});
