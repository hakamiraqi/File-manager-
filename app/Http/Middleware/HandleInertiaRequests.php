<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\TranslationOverride;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $settings = Setting::all_settings();
        $user = $request->user();
        $locale = $user->locale ?? $settings['default_locale'] ?? config('app.locale');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'twoFactorEnabled' => $user?->hasEnabledTwoFactorAuthentication() ?? false,
            ],
            // The bell counter in the top bar.
            'notificationsUnread' => fn () => \App\Models\UserNotification::unreadCount($user),
            'permissions' => $user ? [
                'is_admin' => $user->isAdmin(),
                'manage_exams' => $user->canManageExams(),
                'invigilate' => $user->canInvigilate(),
                'admin' => $user->isAdmin() ? [
                    'appearance' => $user->hasAdminCapability('can_manage_appearance'),
                    'users' => $user->hasAdminCapability('can_manage_users'),
                    'translations' => $user->hasAdminCapability('can_manage_translations'),
                    'statistics' => $user->hasAdminCapability('can_view_statistics'),
                    'settings' => $user->hasAdminCapability('can_manage_settings'),
                ] : null,
            ] : null,
            'appSettings' => [
                'siteName' => $settings['site_name'] ?? config('app.name'),
                'logoUrl' => isset($settings['logo_path']) ? asset('storage/'.$settings['logo_path']) : null,
                'activeSkin' => $settings['active_skin'] ?? 'indigo',
                'customCss' => $settings['custom_css'] ?? '',
                'locale' => $locale,
                'locales' => \App\Support\Locales::active(),
            ],
            'translations' => array_merge(
                $this->baseTranslations($locale),
                TranslationOverride::forLocale($locale),
            ),
            'flash' => [
                'success' => fn () => is_string($s = $request->session()->get('success')) ? __($s) : $s,
                'twoFactorSecret' => fn () => $request->session()->get('twoFactorSecret'),
                'twoFactorQrCodeUri' => fn () => $request->session()->get('twoFactorQrCodeUri'),
                'twoFactorRecoveryCodes' => fn () => $request->session()->get('twoFactorRecoveryCodes'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function baseTranslations(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        if (! is_file($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?: [];
    }
}
