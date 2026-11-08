<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Setting;
use App\Services\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'oauth' => [
                'google' => (string) Setting::get('oauth_google_client_id', '') !== '',
                'github' => (string) Setting::get('oauth_github_client_id', '') !== '',
                'linkedin' => (string) Setting::get('oauth_linkedin_client_id', '') !== '',
            ],
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, NotificationDispatcher $notifier): RedirectResponse
    {
        if ($request->authenticate()) {
            return redirect()->route('two-factor.login');
        }

        $request->session()->regenerate();

        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();
        $notifier->loginOccurred($user);

        return redirect()->intended(route('exams.index', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
