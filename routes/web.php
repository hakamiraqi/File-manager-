<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\SubtitleController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\VideoCommentController;
use App\Http\Controllers\VideoQuestionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->to(auth()->check() ? route('dashboard') : route('login'));
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [FileManagerController::class, 'index'])->name('dashboard');

    Route::get('/folders/tree', [FolderController::class, 'tree'])->name('folders.tree');
    Route::post('/folders', [FolderController::class, 'store'])->name('folders.store');
    Route::put('/folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
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

    Route::post('/folders/{folder}/copy', [FolderController::class, 'copy'])->name('folders.copy');

    Route::get('/files/{file}/questions', [VideoQuestionController::class, 'index'])->name('questions.index');
    Route::post('/files/{file}/questions', [VideoQuestionController::class, 'store'])->name('questions.store');
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

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
