<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NotificationDispatcher;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorAuthenticatedSessionController extends Controller
{
    /**
     * The challenge page. Only reachable mid-login (after correct
     * credentials but before the 2FA code is verified) — a stray visit
     * without a pending "login.id" in the session bounces back to the
     * normal login form instead of erroring.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, TwoFactorAuthenticationService $twoFactor, NotificationDispatcher $notifier): RedirectResponse
    {
        $userId = $request->session()->get('login.id');
        abort_unless($userId, 419);

        $data = $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $user = User::findOrFail($userId);

        if (! empty($data['recovery_code'])) {
            $match = collect($user->recoveryCodes())
                ->first(fn (string $stored) => hash_equals($stored, $data['recovery_code']));

            if (! $match) {
                throw ValidationException::withMessages(['recovery_code' => 'رمز الاسترداد غير صحيح.']);
            }

            $user->consumeRecoveryCode($match);
        } elseif (! empty($data['code'])) {
            if (! $twoFactor->verify($user->two_factor_secret, $data['code'])) {
                throw ValidationException::withMessages(['code' => 'رمز التحقق غير صحيح.']);
            }
        } else {
            throw ValidationException::withMessages(['code' => 'الرجاء إدخال رمز التحقق أو رمز استرداد.']);
        }

        $remember = $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        $notifier->loginOccurred($user);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
