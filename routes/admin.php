<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MailController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TranslationController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::middleware('can:manage-users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::get('/users/ids', [UserController::class, 'ids'])->name('users.ids');
        Route::post('/users/selection', [UserController::class, 'selection'])->name('users.selection');
        Route::post('/users/bulk-status', [UserController::class, 'bulkStatus'])->name('users.bulk-status');
        Route::get('/users/export', [UserController::class, 'export'])->name('users.export');
        Route::get('/users/print', [UserController::class, 'print'])->name('users.print');
        Route::patch('/users/{user}/status', [UserController::class, 'status'])->name('users.status');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        // Login cards (like the clinics system's): pick students, new passwords, print A4 cards.
        $cards = \App\Http\Controllers\Admin\LoginCardController::class;
        Route::get('/cards', [$cards, 'index'])->name('cards.index');
        Route::get('/cards/users', [$cards, 'users'])->name('cards.users');
        Route::post('/cards', [$cards, 'generate'])->middleware('throttle:20,1')->name('cards.generate');
        Route::post('/cards/apply', [$cards, 'apply'])->middleware('throttle:10,1')->name('cards.apply');
        Route::get('/cards/files/{name}', [$cards, 'print'])->name('cards.print');
        Route::get('/cards/files/{name}/download', [$cards, 'download'])->name('cards.download');
        Route::delete('/cards/files/{name}', [$cards, 'destroy'])->name('cards.destroy');
    });

    Route::middleware('can:access-settings-page')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
    });

    Route::middleware('can:manage-settings')->group(function () {
        Route::get('/security', [SecurityController::class, 'index'])->name('security.index');
        Route::post('/security', [SecurityController::class, 'update'])->name('security.update');
        Route::post('/security/ip-rules', [SecurityController::class, 'storeIpRule'])->name('security.ip-rules.store');
        Route::delete('/security/ip-rules/{ipRule}', [SecurityController::class, 'destroyIpRule'])->name('security.ip-rules.destroy');

        Route::get('/mail', [MailController::class, 'index'])->name('mail.index');
        Route::post('/mail', [MailController::class, 'update'])->name('mail.update');
        Route::post('/mail/test', [MailController::class, 'sendTest'])->name('mail.test');

    });

    Route::middleware('can:manage-translations')->group(function () {
        Route::get('/translations', [TranslationController::class, 'index'])->name('translations.index');
        Route::post('/translations', [TranslationController::class, 'update'])->name('translations.update');
        Route::delete('/translations', [TranslationController::class, 'reset'])->name('translations.reset');
        Route::post('/translations/bulk', [TranslationController::class, 'bulk'])->name('translations.bulk');
        Route::post('/languages', [TranslationController::class, 'storeLanguage'])->name('languages.store');
        Route::patch('/languages/{language}', [TranslationController::class, 'updateLanguage'])->name('languages.update');
        Route::delete('/languages/{language}', [TranslationController::class, 'destroyLanguage'])->name('languages.destroy');
    });

    Route::middleware('can:view-statistics')->group(function () {
        Route::get('/activity', [ActivityLogController::class, 'index'])->name('activity.index');
        Route::get('/activity/export', [ActivityLogController::class, 'export'])->name('activity.export');
    });
});
