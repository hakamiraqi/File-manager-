<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OauthAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OauthController extends Controller
{
    private const DRIVER_MAP = [
        'google' => 'google',
        'github' => 'github',
        'linkedin' => 'linkedin-openid',
    ];

    public function redirect(string $provider): RedirectResponse
    {
        $this->assertConfigured($provider);

        return Socialite::driver(self::DRIVER_MAP[$provider])->redirect();
    }

    public function callback(string $provider, NotificationDispatcher $notifier): RedirectResponse
    {
        $this->assertConfigured($provider);

        $oauthUser = Socialite::driver(self::DRIVER_MAP[$provider])->stateless()->user();

        $account = OauthAccount::where('provider', $provider)
            ->where('provider_id', $oauthUser->getId())
            ->first();

        if ($account) {
            $user = $account->user;
        } else {
            $email = $oauthUser->getEmail();
            $user = $email ? User::where('email', $email)->first() : null;

            if (! $user) {
                abort_unless(Setting::get('registration_enabled', '1') === '1', 403, 'التسجيل الذاتي معطّل حالياً.');

                $quotaMb = (int) Setting::get('default_quota_mb', config('filenest.default_quota_mb'));

                $user = DB::transaction(function () use ($oauthUser, $email, $quotaMb) {
                    $user = User::create([
                        'name' => $oauthUser->getName() ?: $oauthUser->getNickname() ?: 'مستخدم جديد',
                        'email' => $email ?: Str::uuid().'@users.filenest.local',
                        'password' => null,
                        'role' => 'user',
                        'storage_quota_bytes' => $quotaMb * 1024 * 1024,
                        'email_verified_at' => $email ? now() : null,
                    ]);

                    return $user;
                });

                $notifier->registrationOccurred($user);
            }

            OauthAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => $oauthUser->getId(),
                'avatar_url' => $oauthUser->getAvatar(),
            ]);
        }

        abort_unless($user->is_active, 403, 'هذا الحساب معطّل. الرجاء التواصل مع الإدارة.');

        Auth::login($user, remember: true);
        $user->forceFill(['last_login_at' => now()])->save();
        $notifier->loginOccurred($user);

        return redirect()->intended(route('exams.index', absolute: false));
    }

    private function assertConfigured(string $provider): void
    {
        if (config('offline.edition')) {
            throw new NotFoundHttpException;
        }
        if (! array_key_exists($provider, self::DRIVER_MAP)) {
            throw new NotFoundHttpException;
        }

        if ((string) Setting::get("oauth_{$provider}_client_id", '') === '') {
            throw new NotFoundHttpException;
        }
    }
}
