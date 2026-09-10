<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorAuthenticationController extends Controller
{
    /**
     * Starts setup: generates a new (unconfirmed) secret and hands the
     * caller a QR-code URI to scan. Two-factor auth only actually takes
     * effect once confirm() below is called with a valid code — a user who
     * abandons setup mid-way is not locked into an incomplete state.
     */
    public function store(Request $request, TwoFactorAuthenticationService $twoFactor): RedirectResponse
    {
        $user = $request->user();
        $secret = $twoFactor->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back()->with([
            'twoFactorSecret' => $secret,
            'twoFactorQrCodeUri' => $twoFactor->qrCodeUri(
                config('app.name'),
                $user->email,
                $secret,
            ),
        ]);
    }

    public function confirm(Request $request, TwoFactorAuthenticationService $twoFactor): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate(['code' => ['required', 'string']]);

        if (! $user->two_factor_secret || ! $twoFactor->verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'رمز التحقق غير صحيح.']);
        }

        $recoveryCodes = $twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        ActivityLog::record('security.2fa_enabled', 'فعّل المصادقة الثنائية لحسابه.');

        return back()->with(['twoFactorRecoveryCodes' => $recoveryCodes]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password']]);

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        ActivityLog::record('security.2fa_disabled', 'عطّل المصادقة الثنائية لحسابه.');

        return back()->with('success', 'تم تعطيل المصادقة الثنائية.');
    }

    public function regenerateRecoveryCodes(Request $request, TwoFactorAuthenticationService $twoFactor): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasEnabledTwoFactorAuthentication(), 403);

        $recoveryCodes = $twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $recoveryCodes])->save();

        return back()->with(['twoFactorRecoveryCodes' => $recoveryCodes]);
    }
}
