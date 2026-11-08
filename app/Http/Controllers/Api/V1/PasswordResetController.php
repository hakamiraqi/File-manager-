<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * Same Laravel password-broker flow the web "forgot password" form uses.
 * The emailed link still points at the web reset page (Auth/ResetPassword)
 * — it works fine opened from a phone's browser — but a client that wants
 * to handle the reset itself (e.g. deep-linking the emailed token back
 * into the app) can call reset() directly with that token instead.
 */
class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['email' => [trans($status)]]);
        }

        return response()->json(['message' => trans($status)]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordResetEvent($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [trans($status)]]);
        }

        return response()->json(['message' => trans($status)]);
    }
}
