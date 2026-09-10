<?php

use App\Http\Controllers\Api\V1\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Api\V1\Admin\AnalyticsController as AdminAnalyticsController;
use App\Http\Controllers\Api\V1\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\MailController as AdminMailController;
use App\Http\Controllers\Api\V1\Admin\PermissionController as AdminPermissionController;
use App\Http\Controllers\Api\V1\Admin\SecurityController as AdminSecurityController;
use App\Http\Controllers\Api\V1\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Api\V1\Admin\TranslationController as AdminTranslationController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrowseController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\FolderController;
use App\Http\Controllers\Api\V1\GuestBrowseController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PlaylistController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicShareController;
use App\Http\Controllers\Api\V1\ShareController;
use App\Http\Controllers\Api\V1\SubtitleController;
use App\Http\Controllers\Api\V1\TrashController;
use App\Http\Controllers\Api\V1\TwoFactorAuthenticationController;
use App\Http\Controllers\Api\V1\VideoCommentController;
use App\Http\Controllers\Api\V1\VideoQuestionController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\RemoteUploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Token-based REST API consumed by the Flutter mobile app (and any other
| non-browser client). Auth is Laravel Sanctum personal access tokens
| (Authorization: Bearer <token>) — there is no session/cookie state here,
| so every route below is safe to call from outside the browser.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::middleware('throttle:api-auth')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');
        Route::post('/auth/two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->name('auth.two-factor-challenge');
        Route::post('/password/forgot-password', [PasswordResetController::class, 'sendResetLink'])->name('password.email');
        Route::post('/password/reset', [PasswordResetController::class, 'reset'])->name('password.store');
    });

    // Anonymous, no Bearer token: public share links and guest-flagged
    // public folders, exactly like the web share.php/guest-access.php
    // route groups.
    Route::middleware('throttle:api')->prefix('public')->name('public.')->group(function () {
        Route::get('/shares/short/{code}', [PublicShareController::class, 'resolveShortCode'])->name('shares.short');
        Route::get('/shares/{token}', [PublicShareController::class, 'show'])->name('shares.show');
        Route::post('/shares/{token}/download', [PublicShareController::class, 'download'])->name('shares.download');

        Route::get('/folders', [GuestBrowseController::class, 'index'])->name('guest.index');
        Route::get('/folders/{folder}', [GuestBrowseController::class, 'show'])->name('guest.show');
        Route::get('/files/{file}/download', [GuestBrowseController::class, 'download'])->name('guest.download');
        Route::get('/files/{file}/thumbnail', [GuestBrowseController::class, 'thumbnail'])->name('guest.thumbnail');
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
        Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        Route::post('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])->name('two-factor.enable');
        Route::post('/user/confirmed-two-factor-authentication', [TwoFactorAuthenticationController::class, 'confirm'])->name('two-factor.confirm');
        Route::delete('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'destroy'])->name('two-factor.disable');
        Route::post('/user/two-factor-recovery-codes', [TwoFactorAuthenticationController::class, 'regenerateRecoveryCodes'])->name('two-factor.recovery-codes');

        Route::get('/browse', [BrowseController::class, 'index'])->name('browse');

        Route::get('/folders/tree', [FolderController::class, 'tree'])->name('folders.tree');
        Route::post('/folders', [FolderController::class, 'store'])->name('folders.store');
        Route::put('/folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
        Route::post('/folders/{folder}/copy', [FolderController::class, 'copy'])->name('folders.copy');
        Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');

        Route::put('/files/{file}', [FileController::class, 'update'])->name('files.update');
        Route::post('/files/{file}/copy', [FileController::class, 'copy'])->name('files.copy');
        Route::delete('/files/{file}', [FileController::class, 'destroy'])->name('files.destroy');
        Route::get('/files/{file}/download', [FileController::class, 'download'])->name('files.download');
        Route::get('/files/{file}/preview', [FileController::class, 'preview'])->name('files.preview');
        Route::get('/files/{file}/preview/{quality}', [FileController::class, 'previewQuality'])->whereNumber('quality')->name('files.preview-quality');
        Route::get('/files/{file}/thumbnail', [FileController::class, 'thumbnail'])->name('files.thumbnail');
        Route::get('/files/{file}/content', [FileController::class, 'content'])->name('files.content');
        Route::get('/download-zip', [FileController::class, 'downloadZip'])->name('files.download-zip');

        Route::get('/files/{file}/subtitles', [SubtitleController::class, 'index'])->name('subtitles.index');
        Route::post('/files/{file}/subtitles', [SubtitleController::class, 'store'])->name('subtitles.store');
        Route::get('/subtitles/{subtitle}', [SubtitleController::class, 'show'])->name('subtitles.show');
        Route::delete('/subtitles/{subtitle}', [SubtitleController::class, 'destroy'])->name('subtitles.destroy');

        // Interactive video quizzes — index() returns the exact payload the
        // app caches to let a downloaded video's quiz work offline; answers
        // recorded while offline are POSTed to answer() once back online.
        Route::get('/questions/import/template', [VideoQuestionController::class, 'template'])->name('questions.import-template');
        Route::get('/files/{file}/questions/report', [VideoQuestionController::class, 'report'])->name('questions.report');
        Route::get('/files/{file}/questions', [VideoQuestionController::class, 'index'])->name('questions.index');
        Route::post('/files/{file}/questions', [VideoQuestionController::class, 'store'])->name('questions.store');
        Route::post('/files/{file}/questions/import', [VideoQuestionController::class, 'import'])->name('questions.import');
        Route::put('/questions/{question}', [VideoQuestionController::class, 'update'])->name('questions.update');
        Route::delete('/questions/{question}', [VideoQuestionController::class, 'destroy'])->name('questions.destroy');
        Route::post('/questions/{question}/answer', [VideoQuestionController::class, 'answer'])->name('questions.answer');

        Route::get('/files/{file}/comments', [VideoCommentController::class, 'index'])->name('comments.index');
        Route::post('/files/{file}/comments', [VideoCommentController::class, 'store'])->name('comments.store');
        Route::delete('/comments/{comment}', [VideoCommentController::class, 'destroy'])->name('comments.destroy');

        Route::post('/uploads/init', [FileUploadController::class, 'init'])->name('uploads.init');
        Route::post('/uploads/chunk', [FileUploadController::class, 'chunk'])->name('uploads.chunk');
        Route::post('/uploads/finalize', [FileUploadController::class, 'finalize'])->name('uploads.finalize');
        Route::post('/uploads/cancel', [FileUploadController::class, 'cancel'])->name('uploads.cancel');
        Route::post('/uploads/remote', [RemoteUploadController::class, 'store'])->name('uploads.remote');

        Route::get('/trash', [TrashController::class, 'index'])->name('trash.index');
        Route::post('/trash/restore', [TrashController::class, 'restore'])->name('trash.restore');
        Route::delete('/trash/{type}/{id}', [TrashController::class, 'forceDelete'])->name('trash.force-delete');
        Route::delete('/trash', [TrashController::class, 'empty'])->name('trash.empty');

        Route::get('/shares', [ShareController::class, 'index'])->name('shares.index');
        Route::post('/shares', [ShareController::class, 'store'])->name('shares.store');
        Route::put('/shares/{share}', [ShareController::class, 'update'])->name('shares.update');
        Route::delete('/shares/{share}', [ShareController::class, 'destroy'])->name('shares.destroy');
        Route::post('/shares/{share}/email', [ShareController::class, 'sendEmail'])->name('shares.email');

        Route::get('/playlists', [PlaylistController::class, 'index'])->name('playlists.index');
        Route::get('/playlists/picker', [PlaylistController::class, 'picker'])->name('playlists.picker');
        Route::post('/playlists', [PlaylistController::class, 'store'])->name('playlists.store');
        Route::get('/playlists/{playlist}', [PlaylistController::class, 'show'])->name('playlists.show');
        Route::put('/playlists/{playlist}', [PlaylistController::class, 'update'])->name('playlists.update');
        Route::delete('/playlists/{playlist}', [PlaylistController::class, 'destroy'])->name('playlists.destroy');
        Route::put('/playlists/{playlist}/reorder', [PlaylistController::class, 'reorder'])->name('playlists.reorder');
        Route::post('/playlists/{playlist}/items', [PlaylistController::class, 'addItem'])->name('playlists.items.store');
        Route::delete('/playlists/{playlist}/items/{item}', [PlaylistController::class, 'removeItem'])->name('playlists.items.destroy');

        // Full parity with routes/admin.php: same "admin" gate + per-section
        // capability gates, just returning JSON instead of Inertia pages.
        Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
            Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

            Route::middleware('can:manage-users')->group(function () {
                Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
                Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
                Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
                Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');
            });

            Route::middleware('can:access-settings-page')->group(function () {
                Route::get('/settings', [AdminSettingController::class, 'index'])->name('settings.index');
                Route::post('/settings', [AdminSettingController::class, 'update'])->name('settings.update');
            });

            Route::middleware('can:manage-settings')->group(function () {
                Route::get('/permissions', [AdminPermissionController::class, 'index'])->name('permissions.index');
                Route::post('/permissions', [AdminPermissionController::class, 'update'])->name('permissions.update');

                Route::get('/security', [AdminSecurityController::class, 'index'])->name('security.index');
                Route::post('/security', [AdminSecurityController::class, 'update'])->name('security.update');
                Route::post('/security/ip-rules', [AdminSecurityController::class, 'storeIpRule'])->name('security.ip-rules.store');
                Route::delete('/security/ip-rules/{ipRule}', [AdminSecurityController::class, 'destroyIpRule'])->name('security.ip-rules.destroy');

                Route::get('/mail', [AdminMailController::class, 'index'])->name('mail.index');
                Route::post('/mail', [AdminMailController::class, 'update'])->name('mail.update');
                Route::post('/mail/test', [AdminMailController::class, 'sendTest'])->name('mail.test');
            });

            Route::middleware('can:manage-translations')->group(function () {
                Route::get('/translations', [AdminTranslationController::class, 'index'])->name('translations.index');
                Route::post('/translations', [AdminTranslationController::class, 'update'])->name('translations.update');
                Route::delete('/translations', [AdminTranslationController::class, 'reset'])->name('translations.reset');
            });

            Route::middleware('can:view-statistics')->group(function () {
                Route::get('/activity', [AdminActivityLogController::class, 'index'])->name('activity.index');
                Route::get('/analytics', [AdminAnalyticsController::class, 'index'])->name('analytics.index');
            });
        });
    });
});
