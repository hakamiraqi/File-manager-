<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'exams.index' : 'login');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/recent', [\App\Http\Controllers\NotificationController::class, 'recent'])->name('notifications.recent');
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{notification}/open', [\App\Http\Controllers\NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->name('notifications.read');
    Route::delete('/notifications/{notification}', [\App\Http\Controllers\NotificationController::class, 'destroy'])->name('notifications.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

// The offline server's activation page (2026-11-05); see EnsureOfflineActivated.
// Halls' servers and the central one (see CentralController); throttled per hall.
Route::middleware('throttle:60,1')->prefix('central')->group(function () {
    Route::get('/exams', [\App\Http\Controllers\Exams\CentralController::class, 'exams']);
    Route::get('/exams/{key}', [\App\Http\Controllers\Exams\CentralController::class, 'exam'])->where('key', '[A-Za-z0-9-]{8,64}');
    Route::post('/results/{key}', [\App\Http\Controllers\Exams\CentralController::class, 'results'])->where('key', '[A-Za-z0-9-]{8,64}')
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
});

// A standby exam server takes the main one's changes (see MirrorController).
Route::get('/mirror/changes', [\App\Http\Controllers\Exams\MirrorController::class, 'changes'])->middleware('throttle:30,1');

// A printed login card's QR code (see CardLoginController).
Route::get('/card/{code}', \App\Http\Controllers\Auth\CardLoginController::class)->where('code', '[A-Za-z0-9]{16,40}')->middleware('throttle:20,1')->name('card.login');

Route::get('/activate', [\App\Http\Controllers\Offline\ActivationController::class, 'show']);
Route::post('/activate', [\App\Http\Controllers\Offline\ActivationController::class, 'store'])->middleware('throttle:10,1');

// The site name and logo for the offline server's Wi-Fi pages (/setup).
// «What is this computer's address?» for the hall: open /ip on any computer (no sign-in).
Route::get('/ip', function (Illuminate\Http\Request $request) {
    $ip = (string) $request->ip();
    $pc = App\Models\Exams\Computer::where('ip', $ip)->first();
    $name = e(config('app.name'));
    $label = $pc ? e($pc->label.($pc->room ? ' — '.$pc->room : '')) : 'غير مسجّلة بعد';

    return response("<!doctype html><html lang=\"ar\" dir=\"rtl\"><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>{$name} — IP</title>"
        .'<body style="font-family:system-ui,Tahoma,sans-serif;text-align:center;padding:10vh 1rem;background:#f8fafc;color:#0f172a">'
        ."<p style=\"color:#64748b\">{$name}</p><p style=\"font-size:12vw;font-weight:700;margin:.2em 0;direction:ltr;font-family:monospace\">".e($ip).'</p>'
        ."<p style=\"font-size:1.5rem\">{$label}</p></body></html>")->header('Cache-Control', 'no-store');
})->middleware('throttle:60,1')->name('ip');

Route::get('/brand.json', function () {
    $logo = \App\Models\Setting::get('logo_path');

    return response()->json([
        'name' => \App\Models\Setting::get('site_name', config('app.name')),
        'logo' => $logo ? asset('storage/'.$logo) : null,
    ]);
});
