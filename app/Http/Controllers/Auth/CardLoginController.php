<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signing in with a printed login card: its QR code is this address with the student's card
 * code (a USB scanner types it into the sign-in field, a phone opens it). Each new printing
 * renews the code, so a lost card stops working with the next one. Students only: staff
 * accounts and accounts with two-factor sign in with their password.
 */
class CardLoginController extends Controller
{
    public function __invoke(Request $request, string $code): RedirectResponse
    {
        $code = User::cardCodeFrom('/card/'.$code);
        $user = $code ? User::where('card_code', $code)->first() : null;
        if (! $user || ! $user->is_active || $user->role === 'admin' || $user->is_examiner || $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('login')->withErrors(['email' => 'بطاقة الدخول غير صالحة أو قديمة. اطلب بطاقة جديدة أو ادخل بكلمة المرور.']);
        }
        if (Auth::check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }
        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        ActivityLog::record('auth.card_login', 'دخل ببطاقة الدخول');

        return redirect()->intended(route('exams.index', absolute: false));
    }
}
