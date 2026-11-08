<?php

use App\Http\Controllers\Exams\ExamController;
use App\Http\Controllers\Exams\ManageController;
use App\Http\Controllers\Exams\ResultController;
use Illuminate\Support\Facades\Route;

// The exam system: students sit exams, examiners (and admins) write and mark them.
Route::middleware(['auth', 'verified'])->prefix('exams')->name('exams.')->group(function () {
    Route::get('/', [ExamController::class, 'index'])->name('index');

    Route::middleware('can:manage-exams')->prefix('manage')->name('manage.')->group(function () {
        Route::get('/', [ManageController::class, 'index'])->name('index');
        Route::post('/', [ManageController::class, 'store'])->name('store');
        Route::get('/{exam}/edit', [ManageController::class, 'edit'])->whereNumber('exam')->name('edit');
        Route::put('/{exam}', [ManageController::class, 'update'])->whereNumber('exam')->name('update');
        Route::delete('/{exam}', [ManageController::class, 'destroy'])->whereNumber('exam')->name('destroy');
        Route::post('/{exam}/duplicate', [ManageController::class, 'duplicate'])->whereNumber('exam')->name('duplicate');
        Route::post('/{exam}/import', [\App\Http\Controllers\Exams\ImportController::class, 'questions'])->whereNumber('exam')->name('import');
        Route::get('/import/template', [\App\Http\Controllers\Exams\ImportController::class, 'questionsTemplate'])->name('import.template');
        Route::post('/students/import', [\App\Http\Controllers\Exams\ImportController::class, 'students'])->name('students.import');

        Route::get('/{exam}/results', [ResultController::class, 'index'])->whereNumber('exam')->name('results');
        Route::get('/{exam}/export', [ResultController::class, 'export'])->whereNumber('exam')->name('export');
        Route::post('/{exam}/regrade', [ResultController::class, 'regrade'])->whereNumber('exam')->name('regrade');
        Route::post('/{exam}/overrides', [ResultController::class, 'override'])->whereNumber('exam')->name('overrides.store');
        Route::delete('/overrides/{override}', [ResultController::class, 'removeOverride'])->name('overrides.destroy');
        Route::put('/answers/{answer}', [ResultController::class, 'grade'])->name('answers.grade');
    });

    Route::get('/{exam}', [ExamController::class, 'show'])->whereNumber('exam')->name('show');
    Route::post('/{exam}/start', [ExamController::class, 'start'])->whereNumber('exam')->middleware('throttle:20,1')->name('start');
    Route::get('/attempts/{attempt}', [ExamController::class, 'take'])->name('attempts.take');
    Route::post('/attempts/{attempt}/answers', [ExamController::class, 'save'])->middleware('throttle:240,1')->name('attempts.save');
    Route::post('/attempts/{attempt}/events', [ExamController::class, 'event'])->middleware('throttle:120,1')->name('attempts.event');
    Route::post('/attempts/{attempt}/submit', [ExamController::class, 'submit'])->name('attempts.submit');
    Route::get('/attempts/{attempt}/result', [ExamController::class, 'result'])->name('attempts.result');
});
