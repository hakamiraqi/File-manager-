<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OfflineExportController;
use App\Http\Controllers\Admin\MailController;
use App\Http\Controllers\Admin\OcrController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TranslationController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // «نسخة للسيرفر بدون إنترنت» (2026-11-10): the whole site as .tar parts, main admin only.
    Route::get('/offline-export', [OfflineExportController::class, 'index'])->name('offline-export');
    Route::get('/offline-export/part/{n}', [OfflineExportController::class, 'part'])->whereNumber('n')->name('offline-export.part');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::middleware('can:manage-users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/status', [UserController::class, 'status'])->name('users.status');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    Route::middleware('can:access-settings-page')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
    });

    Route::middleware('can:manage-settings')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::post('/permissions', [PermissionController::class, 'update'])->name('permissions.update');

        Route::get('/security', [SecurityController::class, 'index'])->name('security.index');
        Route::post('/security', [SecurityController::class, 'update'])->name('security.update');
        Route::post('/security/ip-rules', [SecurityController::class, 'storeIpRule'])->name('security.ip-rules.store');
        Route::delete('/security/ip-rules/{ipRule}', [SecurityController::class, 'destroyIpRule'])->name('security.ip-rules.destroy');

        Route::get('/mail', [MailController::class, 'index'])->name('mail.index');
        Route::post('/mail', [MailController::class, 'update'])->name('mail.update');
        Route::post('/mail/test', [MailController::class, 'sendTest'])->name('mail.test');

        Route::get('/covers', [\App\Http\Controllers\Admin\CoverController::class, 'index'])->name('covers.index');
        Route::post('/covers', [\App\Http\Controllers\Admin\CoverController::class, 'update'])->name('covers.update');
        Route::post('/covers/run', [\App\Http\Controllers\Admin\CoverController::class, 'runNow'])->name('covers.run');
        Route::post('/covers/retry', [\App\Http\Controllers\Admin\CoverController::class, 'retry'])->name('covers.retry');
        Route::delete('/covers/files/{file}', [\App\Http\Controllers\Admin\CoverController::class, 'remove'])->name('covers.remove');
        Route::get('/ocr', [OcrController::class, 'index'])->name('ocr.index');
        Route::post('/ocr', [OcrController::class, 'update'])->name('ocr.update');
        Route::post('/ocr/queue', [OcrController::class, 'queue'])->name('ocr.queue');
        Route::post('/ocr/run', [OcrController::class, 'runNow'])->name('ocr.run');
        Route::post('/ocr/retry-failed', [OcrController::class, 'retryFailed'])->name('ocr.retry-failed');
        Route::post('/ocr/files/{file}/{action}', [OcrController::class, 'file'])->whereIn('action', ['retry', 'skip', 'prioritize'])->name('ocr.file');
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
        Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('/analytics/export', [AnalyticsController::class, 'export'])->name('analytics.export');
    });
});
