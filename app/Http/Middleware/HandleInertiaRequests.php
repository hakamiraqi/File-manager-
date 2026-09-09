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
            ],
            'quota' => $user ? [
                'used' => $user->storage_used_bytes,
                'total' => $user->quotaBytes(),
                'percent' => $user->storageUsagePercent(),
            ] : null,
            'permissions' => $user ? [
                'view' => $user->canPerform('view'),
                'download' => $user->canPerform('download'),
                'share' => $user->canPerform('share'),
                'upload' => $user->canPerform('upload'),
                'delete' => $user->canPerform('delete'),
                'rename' => $user->canPerform('rename'),
                'move' => $user->canPerform('move'),
                'copy' => $user->canPerform('copy'),
                'new_folder' => $user->canPerform('new_folder'),
                'delete_folder' => $user->canPerform('delete_folder'),
                'rename_folder' => $user->canPerform('rename_folder'),
                'is_admin' => $user->isAdmin(),
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
                'guestAccessEnabled' => (bool) ($settings['guest_access_enabled'] ?? false),
                'remoteUploadEnabled' => (bool) ($settings['remote_upload_enabled'] ?? false),
            ],
            'translations' => array_merge(
                $this->baseTranslations($locale),
                TranslationOverride::forLocale($locale),
            ),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'shareUrl' => fn () => $request->session()->get('shareUrl'),
                'shareId' => fn () => $request->session()->get('shareId'),
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
