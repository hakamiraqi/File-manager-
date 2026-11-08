<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationDispatcher;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new account and immediately issue an API token, so a
     * mobile client can move straight from sign-up into the app. Mirrors
     * every admin-configurable rule the web registration form respects.
     */
    public function register(Request $request, NotificationDispatcher $notifier): JsonResponse
    {
        abort_unless(Setting::get('registration_enabled', '1') === '1', 403, 'التسجيل الذاتي معطّل حالياً. الرجاء التواصل مع الإدارة.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $quotaMb = (int) Setting::get('default_quota_mb', config('filenest.default_quota_mb'));

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => Setting::get('registration_role', 'user'),
            'storage_quota_bytes' => $quotaMb * 1024 * 1024,
        ]);

        event(new Registered($user));
        \App\Services\Notify::to(\App\Services\Notify::admins('can_manage_users'), 'admin', 'user_registered', "مستخدم جديد: {$user->name}",
            $user->email, route('admin.users.index', ['q' => $user->email], false));
        $notifier->registrationOccurred($user);

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return response()->json([
            'two_factor_required' => false,
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['تم تعليق هذا الحساب. الرجاء التواصل مع الإدارة.'],
            ]);
        }

        // A confirmed 2FA secret means the password alone isn't enough: no
        // token is issued yet, only a short-lived, self-contained pending
        // token identifying which account still needs its code verified —
        // see twoFactorChallenge() below. There's no server-side session to
        // stash this in the way the web login flow does, since the API is
        // stateless.
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'two_factor_required' => true,
                'two_factor_token' => $this->issuePendingTwoFactorToken($user),
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return response()->json([
            'two_factor_required' => false,
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    /**
     * Completes a login that stopped at two_factor_required, using either
     * a TOTP code or a (single-use) recovery code.
     */
    public function twoFactorChallenge(Request $request, TwoFactorAuthenticationService $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'two_factor_token' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $user = $this->resolvePendingTwoFactorUser($data['two_factor_token']);

        if (! empty($data['recovery_code'])) {
            $match = collect($user->recoveryCodes())
                ->first(fn (string $stored) => hash_equals($stored, $data['recovery_code']));

            if (! $match) {
                throw ValidationException::withMessages(['recovery_code' => ['رمز الاسترداد غير صحيح.']]);
            }

            $user->consumeRecoveryCode($match);
        } elseif (! empty($data['code'])) {
            if (! $twoFactor->verify($user->two_factor_secret, $data['code'])) {
                throw ValidationException::withMessages(['code' => ['رمز التحقق غير صحيح.']]);
            }
        } else {
            throw ValidationException::withMessages(['code' => ['الرجاء إدخال رمز التحقق أو رمز استرداد.']]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($this->deviceName($request))->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    private function deviceName(Request $request): string
    {
        return (string) $request->string('device_name', 'flutter-app');
    }

    private function issuePendingTwoFactorToken(User $user): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]));
    }

    private function resolvePendingTwoFactorUser(string $token): User
    {
        $invalid = fn () => ValidationException::withMessages([
            'two_factor_token' => ['انتهت صلاحية الجلسة، الرجاء تسجيل الدخول مرة أخرى.'],
        ]);

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            throw $invalid();
        }

        if (! is_array($payload) || ($payload['expires_at'] ?? 0) < now()->timestamp) {
            throw $invalid();
        }

        return User::findOrFail($payload['user_id']);
    }
}
