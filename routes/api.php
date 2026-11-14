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

// The office editor's own calls (no user session): fetch a file and send back the saved one.
// Signed, short-lived links handed to it in the editor settings; the save also carries a JWT.
Route::middleware('signed:relative')->prefix('office-link')->group(function () {
    Route::get('/files/{file}/download', [\App\Http\Controllers\OfficeController::class, 'download'])->name('office.download');
    Route::post('/files/{file}/callback', [\App\Http\Controllers\OfficeController::class, 'callback'])->name('office.callback');
});

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

        // The visitors' site in the app: which sections are on, the library, theses and «تواصل معنا».
        // A signed-in app sends its token here too, so these read the user when there is one.
        Route::get('/site', [\App\Http\Controllers\Api\V1\MobileController::class, 'site'])->name('site');
        Route::get('/library', [\App\Http\Controllers\Api\V1\LibraryController::class, 'index'])->name('library.index');
        Route::get('/library/books/{book}', [\App\Http\Controllers\Api\V1\LibraryController::class, 'show'])->name('library.show');
        Route::get('/library/map-link', [\App\Http\Controllers\Api\V1\LibraryController::class, 'mapLink'])->name('library.map-link');
        Route::get('/library/map-data', [\App\Http\Controllers\Api\V1\LibraryController::class, 'mapData'])->name('library.map-data');
        Route::get('/library/map-bundle', [\App\Http\Controllers\Api\V1\LibraryController::class, 'mapBundle'])->name('library.map-bundle');
        Route::get('/theses', [\App\Http\Controllers\Api\V1\MobileController::class, 'theses'])->name('theses');
        Route::get('/contact', [\App\Http\Controllers\Api\V1\MobileController::class, 'contactInfo'])->name('contact.info');
        Route::post('/contact', [\App\Http\Controllers\Api\V1\MobileController::class, 'contactStore'])->middleware('throttle:6,1')->name('contact.store');
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

        // Website features in the app (2026-11-06): library, notifications, saved, inbox, picture.
        Route::get('/web-link', [\App\Http\Controllers\AppPageController::class, 'link'])->name('web-link');
        Route::get('/me/badges', [\App\Http\Controllers\Api\V1\MobileController::class, 'badges'])->name('me.badges');
        Route::post('/profile/avatar', [\App\Http\Controllers\Api\V1\MobileController::class, 'avatar'])->name('profile.avatar');
        Route::delete('/profile/avatar', [\App\Http\Controllers\Api\V1\MobileController::class, 'deleteAvatar'])->name('profile.avatar.destroy');
        Route::get('/notifications', [\App\Http\Controllers\Api\V1\MobileController::class, 'notifications'])->name('notifications.index');
        Route::post('/notifications/read-all', [\App\Http\Controllers\Api\V1\MobileController::class, 'readAllNotifications'])->name('notifications.read-all');
        Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Api\V1\MobileController::class, 'readNotification'])->name('notifications.read');
        Route::delete('/notifications/{notification}', [\App\Http\Controllers\Api\V1\MobileController::class, 'deleteNotification'])->name('notifications.destroy');
        Route::get('/saved', [\App\Http\Controllers\Api\V1\MobileController::class, 'saved'])->name('saved.index');
        Route::get('/lectures', [\App\Http\Controllers\LectureController::class, 'api'])->name('lectures.index');
        Route::post('/files/new-document', [\App\Http\Controllers\OfficeController::class, 'create'])->name('files.new-document');
        // The reader's own place, bookmarks and notes on a file, for the app's reader (kept offline, synced here).
        Route::get('/files/{file}/reader-state', [\App\Http\Controllers\ReaderController::class, 'state'])->name('api.v1.files.reader-state');
        Route::put('/files/{file}/reader-state', [\App\Http\Controllers\ReaderController::class, 'save'])->name('api.v1.files.reader-state.save');
        // A book's cover on a file, for the apps (2026-11-14): search and pick, upload, or a page of the file.
        Route::get('/files/{file}/cover/search', [\App\Http\Controllers\FileCoverController::class, 'search'])->middleware('throttle:30,1')->name('api.v1.files.cover.search');
        Route::post('/files/{file}/cover', [\App\Http\Controllers\FileCoverController::class, 'store'])->name('api.v1.files.cover.store');
        Route::delete('/files/{file}/cover', [\App\Http\Controllers\FileCoverController::class, 'destroy'])->name('api.v1.files.cover.destroy');
        Route::post('/saved/toggle', [\App\Http\Controllers\Api\V1\MobileController::class, 'toggleSaved'])->name('saved.toggle');
        Route::post('/saved/forget-download', [\App\Http\Controllers\Api\V1\MobileController::class, 'forgetDownload'])->name('saved.forget');
        Route::get('/library/mine', [\App\Http\Controllers\Api\V1\LibraryController::class, 'mine'])->name('library.mine');
        Route::post('/library/books/{book}/hold', [\App\Http\Controllers\Api\V1\LibraryController::class, 'hold'])->name('library.hold');
        Route::delete('/library/holds/{hold}', [\App\Http\Controllers\Api\V1\LibraryController::class, 'cancelHold'])->name('library.hold.cancel');
        Route::post('/library/loans/{loan}/renew', [\App\Http\Controllers\Api\V1\LibraryController::class, 'renew'])->name('library.renew');
        Route::middleware('can:manage-library')->prefix('library/manage')->name('library.manage.')->group(function () {
            Route::get('/stats', [\App\Http\Controllers\Api\V1\LibraryController::class, 'stats'])->name('stats');
            Route::get('/loans', [\App\Http\Controllers\Api\V1\LibraryController::class, 'loans'])->name('loans');
            Route::get('/holds', [\App\Http\Controllers\Api\V1\LibraryController::class, 'holdsQueue'])->name('holds');
            Route::post('/loans/{loan}/pay', [\App\Http\Controllers\Api\V1\LibraryController::class, 'payFine'])->name('loans.pay');
            Route::post('/loans/{loan}/waive', [\App\Http\Controllers\Api\V1\LibraryController::class, 'waiveFine'])->name('loans.waive');
            // The lending desk: the same JSON endpoints the website's counter uses.
            Route::post('/counter/scan', [\App\Http\Controllers\Library\CounterController::class, 'scan'])->name('counter.scan');
            Route::get('/counter/members', [\App\Http\Controllers\Library\CounterController::class, 'members'])->name('counter.members');
            Route::post('/counter/member', [\App\Http\Controllers\Library\CounterController::class, 'member'])->name('counter.member');

            // The rest of the website's library management, for the apps (2026-11-10): the same
            // controllers as the web pages, their replies turned into JSON by "web-json".
            Route::middleware('web-json')->group(function () {
                $books = \App\Http\Controllers\Library\BookController::class;
                Route::get('/books', [$books, 'index'])->name('books');
                Route::post('/books', [$books, 'store'])->name('books.store');
                Route::put('/books/{book}', [$books, 'update'])->name('books.update');
                Route::delete('/books/{book}', [$books, 'destroy'])->name('books.destroy');
                Route::get('/isbn/{isbn}', [$books, 'isbn'])->name('isbn');
                Route::post('/cover-text', [$books, 'coverText'])->middleware('throttle:30,1')->name('cover-text');
                Route::post('/books/{book}/copies', [$books, 'addCopies'])->name('copies.store');
                Route::put('/copies/{copy}', [$books, 'updateCopy'])->name('copies.update');
                Route::delete('/copies/{copy}', [$books, 'destroyCopy'])->name('copies.destroy');
                Route::get('/import/template', [\App\Http\Controllers\Library\ImportController::class, 'template'])->name('import.template');
                Route::post('/import', [\App\Http\Controllers\Library\ImportController::class, 'store'])->name('import');

                $sections = \App\Http\Controllers\Library\SectionController::class;
                Route::get('/sections', [$sections, 'index'])->name('sections');
                Route::post('/sections', [$sections, 'store'])->name('sections.store');
                Route::put('/sections/{section}', [$sections, 'update'])->name('sections.update');
                Route::delete('/sections/{section}', [$sections, 'destroy'])->name('sections.destroy');

                $members = \App\Http\Controllers\Library\MemberController::class;
                Route::get('/members', [$members, 'index'])->name('members');
                Route::post('/members', [$members, 'store'])->name('members.store');
                Route::put('/members/{member}', [$members, 'update'])->name('members.update');
                Route::delete('/members/{member}', [$members, 'destroy'])->name('members.destroy');
                Route::post('/members/import', [$members, 'import'])->name('members.import');

                $settings = \App\Http\Controllers\Library\SettingsController::class;
                Route::get('/settings', [$settings, 'index'])->name('settings');
                Route::post('/settings', [$settings, 'update'])->name('settings.update');
                Route::post('/holidays', [$settings, 'addHoliday'])->name('holidays.store');
                Route::delete('/holidays/{holiday}', [$settings, 'removeHoliday'])->name('holidays.destroy');

                Route::get('/reports', [\App\Http\Controllers\Library\ReportController::class, 'index'])->name('reports');
            });
        });
        Route::middleware('can:manage-contact')->group(function () {
            Route::get('/inbox', [\App\Http\Controllers\Api\V1\MobileController::class, 'inbox'])->name('inbox.index');
            Route::patch('/inbox/{message}', [\App\Http\Controllers\Api\V1\MobileController::class, 'inboxUpdate'])->name('inbox.update');
            Route::delete('/inbox/{message}', [\App\Http\Controllers\Api\V1\MobileController::class, 'inboxDestroy'])->name('inbox.destroy');
        });

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

                // «التعرف على النصوص»: the website page's controller, as JSON (2026-11-10).
                Route::middleware('web-json')->group(function () {
                    $ocr = \App\Http\Controllers\Admin\OcrController::class;
                    Route::get('/ocr', [$ocr, 'index'])->name('ocr.index');
                    Route::post('/ocr', [$ocr, 'update'])->name('ocr.update');
                    Route::post('/ocr/queue', [$ocr, 'queue'])->name('ocr.queue');
                    Route::post('/ocr/run', [$ocr, 'runNow'])->name('ocr.run');
                    Route::post('/ocr/retry-failed', [$ocr, 'retryFailed'])->name('ocr.retry-failed');
                    Route::post('/ocr/files/{file}/{action}', [$ocr, 'file'])->whereIn('action', ['retry', 'skip', 'prioritize'])->name('ocr.file');

                    // «أغلفة الكتب» (2026-11-14): the website page's controller, as JSON.
                    $covers = \App\Http\Controllers\Admin\CoverController::class;
                    Route::get('/covers', [$covers, 'index'])->name('covers.index');
                    Route::post('/covers', [$covers, 'update'])->name('covers.update');
                    Route::post('/covers/run', [$covers, 'runNow'])->name('covers.run');
                    Route::post('/covers/retry', [$covers, 'retry'])->name('covers.retry');
                    Route::delete('/covers/files/{file}', [$covers, 'remove'])->name('covers.remove');
                });
            });

            Route::middleware('can:manage-translations')->group(function () {
                Route::get('/translations', [AdminTranslationController::class, 'index'])->name('translations.index');
                Route::post('/translations', [AdminTranslationController::class, 'update'])->name('translations.update');
                Route::delete('/translations', [AdminTranslationController::class, 'reset'])->name('translations.reset');
            });

            Route::middleware('can:view-statistics')->group(function () {
                Route::get('/activity', [AdminActivityLogController::class, 'index'])->name('activity.index');
                Route::get('/analytics', [AdminAnalyticsController::class, 'index'])->name('analytics.index');
                // The site's staff analytics page (2026-11-12) for the apps, its props as JSON.
                Route::get('/staff-analytics', [\App\Http\Controllers\Admin\AnalyticsController::class, 'index'])
                    ->middleware('web-json')->name('staff-analytics');
            });
        });
    });
});
