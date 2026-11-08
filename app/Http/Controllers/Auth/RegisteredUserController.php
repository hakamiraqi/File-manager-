<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\CaptchaValidator;
use App\Services\NotificationDispatcher;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request, CaptchaValidator $captcha): Response
    {
        abort_unless(Setting::get('registration_enabled', '1') === '1', 403, 'التسجيل الذاتي معطّل حالياً. الرجاء التواصل مع الإدارة.');

        return Inertia::render('Auth/Register', [
            'captcha' => $this->captchaProps($request, $captcha),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, CaptchaValidator $captcha, NotificationDispatcher $notifier): RedirectResponse
    {
        abort_unless(Setting::get('registration_enabled', '1') === '1', 403);

        if (Setting::get('show_captcha_register', false)) {
            $captcha->assertValid($request);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $quotaMb = (int) Setting::get('default_quota_mb', config('filenest.default_quota_mb'));

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'user',
            'storage_quota_bytes' => $quotaMb * 1024 * 1024,
        ]);

        event(new Registered($user));
        \App\Services\Notify::to(\App\Services\Notify::admins('can_manage_users'), 'admin', 'user_registered', "مستخدم جديد: {$user->name}",
            $user->email, route('admin.users.index', ['q' => $user->email], false));

        $notifier->registrationOccurred($user);

        Auth::login($user);

        return redirect(route('exams.index', absolute: false));
    }

    /**
     * @return array{enabled: bool, question?: string, siteKey?: string, invisible?: bool}
     */
    public static function captchaProps(Request $request, CaptchaValidator $captcha): array
    {
        if (! Setting::get('show_captcha_register', false)) {
            return ['enabled' => false];
        }

        if ($captcha->isRecaptchaEnabled()) {
            return ['enabled' => true, 'siteKey' => $captcha->siteKey(), 'invisible' => $captcha->isInvisible()];
        }

        return ['enabled' => true, ...$captcha->generateMathChallenge($request)];
    }
}
