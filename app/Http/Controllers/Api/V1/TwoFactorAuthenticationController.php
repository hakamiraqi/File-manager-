<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors app\Http\Controllers\TwoFactorAuthenticationController (the web
 * settings page) as JSON endpoints, so the Flutter app can offer 2FA setup
 * without needing a browser.
 *
 * The web version gates its equivalent routes behind Laravel's session-based
 * password.confirm middleware — a stolen/hijacked session must not be
 * enough to silently take over or reset a user's 2FA. There is no session
 * here, so every state-changing action instead requires the current
 * password on the request itself.
 */
class TwoFactorAuthenticationController extends Controller
{
    public function store(Request $request, TwoFactorAuthenticationService $twoFactor): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:sanctum']]);

        $user = $request->user();
        $secret = $twoFactor->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'qr_code_uri' => $twoFactor->qrCodeUri(config('app.name'), $user->email, $secret),
        ]);
    }

    public function confirm(Request $request, TwoFactorAuthenticationService $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'current_password:sanctum'],
            'code' => ['required', 'string'],
        ]);
        $user = $request->user();

        if (! $user->two_factor_secret || ! $twoFactor->verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'رمز التحقق غير صحيح.']);
        }

        $recoveryCodes = $twoFactor->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        ActivityLog::record('security.2fa_enabled', 'فعّل المصادقة الثنائية لحسابه.');

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:sanctum']]);

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        ActivityLog::record('security.2fa_disabled', 'عطّل المصادقة الثنائية لحسابه.');

        return response()->json(['message' => 'تم تعطيل المصادقة الثنائية.']);
    }

    public function regenerateRecoveryCodes(Request $request, TwoFactorAuthenticationService $twoFactor): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:sanctum']]);

        $user = $request->user();
        abort_unless($user->hasEnabledTwoFactorAuthentication(), 403);

        $recoveryCodes = $twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $recoveryCodes])->save();

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }
}
