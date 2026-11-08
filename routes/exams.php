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
        Route::post('/images', [ManageController::class, 'image'])->middleware('throttle:60,1')->name('images.store');
        Route::post('/students/import', [\App\Http\Controllers\Exams\ImportController::class, 'students'])->name('students.import');

        $bank = \App\Http\Controllers\Exams\BankController::class;
        Route::get('/bank', [$bank, 'index'])->name('bank');
        Route::post('/bank/categories', [$bank, 'store'])->name('bank.categories.store');
        Route::put('/bank/categories/{category}', [$bank, 'update'])->name('bank.categories.update');
        Route::delete('/bank/categories/{category}', [$bank, 'destroy'])->name('bank.categories.destroy');
        Route::post('/bank/categories/{category}/import', [$bank, 'import'])->name('bank.categories.import');
        Route::delete('/bank/questions/{question}', [$bank, 'destroyQuestion'])->name('bank.questions.destroy');
        Route::post('/questions/{question}/to-bank', [$bank, 'fromExam'])->name('bank.from-exam');
        Route::post('/{exam}/from-bank', [$bank, 'toExam'])->whereNumber('exam')->name('bank.to-exam');

        Route::get('/{exam}/print', [ManageController::class, 'print'])->whereNumber('exam')->name('print');
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
    Route::post('/attempts/{attempt}/check', [ExamController::class, 'check'])->middleware('throttle:240,1')->name('attempts.check');
    Route::post('/attempts/{attempt}/upload', [ExamController::class, 'upload'])->middleware('throttle:30,1')->name('attempts.upload');
    Route::get('/answers/{answer}/file', [ExamController::class, 'file'])->name('answers.file');
    Route::get('/images/{name}', [ExamController::class, 'image'])->where('name', '[a-f0-9]{64}\.[a-z]{3,4}')->name('image');
    Route::post('/attempts/{attempt}/events', [ExamController::class, 'event'])->middleware('throttle:120,1')->name('attempts.event');
    Route::post('/attempts/{attempt}/submit', [ExamController::class, 'submit'])->name('attempts.submit');
    Route::get('/attempts/{attempt}/result', [ExamController::class, 'result'])->name('attempts.result');
});
