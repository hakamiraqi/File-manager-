<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\SubtitleController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\VideoCommentController;
use App\Http\Controllers\VideoQuestionController;
use Illuminate\Support\Facades\Route;

// The apps and the offline server for download (public, no login).
// The apps open some website pages through a short-lived signed link (see AppPageController).
Route::get('/app/open', [\App\Http\Controllers\AppPageController::class, 'open'])->middleware('throttle:30,1')->name('app.open');

Route::get('/download', fn () => view('downloads', ['releases' => \App\Support\AppReleases::all()]))->name('downloads');

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    // With guest access on, visitors land on the public site instead of the login form.
    return redirect()->route(\App\Support\PublicSite::enabled() ? 'guest.index' : 'login');
});

// «تواصل معنا»: open to visitors and users (when switched on).
Route::get('/contact', [\App\Http\Controllers\ContactController::class, 'create'])->name('contact.create');
Route::post('/contact', [\App\Http\Controllers\ContactController::class, 'store'])->middleware('throttle:6,1')->name('contact.store');
Route::middleware(['auth', 'verified', 'can:manage-contact'])->group(function () {
    Route::get('/inbox', [\App\Http\Controllers\ContactController::class, 'inbox'])->name('contact.inbox');
    Route::patch('/inbox/{message}', [\App\Http\Controllers\ContactController::class, 'update'])->name('contact.update');
    Route::delete('/inbox/{message}', [\App\Http\Controllers\ContactController::class, 'destroy'])->name('contact.destroy');
});

// Thesis catalogue: guests see the public theses when guest access is on.
Route::get('/theses', [\App\Http\Controllers\ThesisController::class, 'index'])->name('theses.index');
Route::get('/oai', \App\Http\Controllers\OaiController::class)->middleware('throttle:60,1')->name('oai');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/theses/similar', [\App\Http\Controllers\ThesisController::class, 'similar'])->name('theses.similar');
    Route::get('/theses/export', [\App\Http\Controllers\ThesisController::class, 'export'])->name('theses.export');
    Route::get('/theses/template', [\App\Http\Controllers\ThesisController::class, 'template'])->name('theses.template');
    Route::post('/theses/import', [\App\Http\Controllers\ThesisController::class, 'import'])->name('theses.import');

    Route::get('/dashboard', [FileManagerController::class, 'index'])->name('dashboard');
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/recent', [\App\Http\Controllers\NotificationController::class, 'recent'])->name('notifications.recent');
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{notification}/open', [\App\Http\Controllers\NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->name('notifications.read');
    Route::delete('/notifications/{notification}', [\App\Http\Controllers\NotificationController::class, 'destroy'])->name('notifications.destroy');

    Route::get('/folders/tree', [FolderController::class, 'tree'])->name('folders.tree');
    Route::post('/folders', [FolderController::class, 'store'])->name('folders.store');
    Route::put('/folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');

    Route::put('/files/{file}', [FileController::class, 'update'])->name('files.update');
    Route::post('/files/{file}/copy', [FileController::class, 'copy'])->name('files.copy');
    Route::delete('/files/{file}', [FileController::class, 'destroy'])->name('files.destroy');
    Route::get('/files/{file}/download', [FileController::class, 'download'])->name('files.download');
    Route::get('/files/{file}/preview', [FileController::class, 'preview'])->name('files.preview');
    Route::get('/files/{file}/document-preview', [FileController::class, 'documentPreview'])->name('files.document-preview');
    Route::get('/files/{file}/preview/{quality}', [FileController::class, 'previewQuality'])->whereNumber('quality')->name('files.preview-quality');
    Route::get('/files/{file}/thumbnail', [FileController::class, 'thumbnail'])->name('files.thumbnail');
    Route::get('/files/{file}/content', [FileController::class, 'content'])->name('files.content');
    Route::get('/download-zip', [FileController::class, 'downloadZip'])->name('files.download-zip');

    Route::get('/files/{file}/subtitles', [SubtitleController::class, 'index'])->name('subtitles.index');
    Route::post('/files/{file}/subtitles', [SubtitleController::class, 'store'])->name('subtitles.store');
    Route::get('/subtitles/{subtitle}', [SubtitleController::class, 'show'])->name('subtitles.show');
    Route::delete('/subtitles/{subtitle}', [SubtitleController::class, 'destroy'])->name('subtitles.destroy');

    Route::post('/folders/{folder}/copy', [FolderController::class, 'copy'])->name('folders.copy');

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
    Route::post('/uploads/remote', [\App\Http\Controllers\RemoteUploadController::class, 'store'])->name('uploads.remote');

    Route::get('/trash', [TrashController::class, 'index'])->name('trash.index');
    Route::post('/trash/restore', [TrashController::class, 'restore'])->name('trash.restore');
    Route::delete('/trash/{type}/{id}', [TrashController::class, 'forceDelete'])->name('trash.force-delete');
    Route::delete('/trash', [TrashController::class, 'empty'])->name('trash.empty');

    Route::get('/shares', [ShareController::class, 'index'])->name('shares.index');
    Route::post('/shares', [ShareController::class, 'store'])->name('shares.store');
    Route::put('/shares/{share}', [ShareController::class, 'update'])->name('shares.update');
    Route::delete('/shares/{share}', [ShareController::class, 'destroy'])->name('shares.destroy');
    Route::post('/shares/{share}/email', [ShareController::class, 'sendEmail'])->name('shares.email');

    Route::get('/saved', [\App\Http\Controllers\SavedController::class, 'index'])->name('saved.index');
    Route::get('/lectures', [\App\Http\Controllers\LectureController::class, 'index'])->name('lectures.index');
    // The office editor (/office itself is the editor's own address on this site).
    Route::get('/editor/files/{file}', [\App\Http\Controllers\OfficeController::class, 'edit'])->name('office.edit');
    Route::post('/editor/new', [\App\Http\Controllers\OfficeController::class, 'create'])->name('office.create');
    // The PDF reader: full screen, with each reader's own place, bookmarks and notes.
    // PDF tools: merge, split, organise, compress, protect, watermark, numbers, conversions.
    Route::get('/pdf-tools', [\App\Http\Controllers\PdfToolController::class, 'index'])->name('pdf-tools.index');
    Route::get('/pdf-tools/files', [\App\Http\Controllers\PdfToolController::class, 'files'])->name('pdf-tools.files');
    Route::post('/pdf-tools/{tool}', [\App\Http\Controllers\PdfToolController::class, 'run'])->name('pdf-tools.run');
    Route::post('/reader/files/{file}/copy', [\App\Http\Controllers\PdfToolController::class, 'saveCopy'])->name('reader.copy');
    Route::get('/reader/files/{file}', [\App\Http\Controllers\ReaderController::class, 'show'])->name('reader.show');
    Route::get('/reader/files/{file}/state', [\App\Http\Controllers\ReaderController::class, 'state'])->name('reader.state');
    Route::put('/reader/files/{file}/state', [\App\Http\Controllers\ReaderController::class, 'save'])->name('reader.state.save');
    Route::put('/reader/signature', [\App\Http\Controllers\ReaderController::class, 'saveSignature'])->name('reader.signature');
    Route::delete('/reader/signature', [\App\Http\Controllers\ReaderController::class, 'forgetSignature'])->name('reader.signature.forget');
    Route::post('/saved/toggle', [\App\Http\Controllers\SavedController::class, 'toggle'])->name('saved.toggle');
    Route::delete('/saved/downloads', [\App\Http\Controllers\SavedController::class, 'forgetDownload'])->name('saved.downloads.forget');
    Route::get('/playlists', [PlaylistController::class, 'index'])->name('playlists.index');
    Route::get('/playlists/picker', [PlaylistController::class, 'picker'])->name('playlists.picker');
    Route::post('/playlists', [PlaylistController::class, 'store'])->name('playlists.store');
    Route::get('/playlists/{playlist}', [PlaylistController::class, 'show'])->name('playlists.show');
    Route::put('/playlists/{playlist}', [PlaylistController::class, 'update'])->name('playlists.update');
    Route::delete('/playlists/{playlist}', [PlaylistController::class, 'destroy'])->name('playlists.destroy');
    Route::put('/playlists/{playlist}/reorder', [PlaylistController::class, 'reorder'])->name('playlists.reorder');
    Route::post('/playlists/{playlist}/items', [PlaylistController::class, 'addItem'])->name('playlists.items.store');
    Route::delete('/playlists/{playlist}/items/{item}', [PlaylistController::class, 'removeItem'])->name('playlists.items.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

// The offline server's activation page (2026-11-05); see EnsureOfflineActivated.
Route::get('/activate', [\App\Http\Controllers\Offline\ActivationController::class, 'show']);
Route::post('/activate', [\App\Http\Controllers\Offline\ActivationController::class, 'store'])->middleware('throttle:10,1');

// The site name and logo for the offline server's Wi-Fi pages (/setup).
Route::get('/brand.json', function () {
    $logo = \App\Models\Setting::get('logo_path');

    return response()->json([
        'name' => \App\Models\Setting::get('site_name', config('app.name', 'FileNest')),
        'logo' => $logo ? asset('storage/'.$logo) : null,
    ]);
});
