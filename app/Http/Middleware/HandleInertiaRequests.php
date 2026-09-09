<?php

namespace App\Http\Middleware;

use App\Models\Setting;
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
            'appSettings' => [
                'siteName' => $settings['site_name'] ?? config('app.name'),
                'logoUrl' => isset($settings['logo_path']) ? asset('storage/'.$settings['logo_path']) : null,
                'activeSkin' => $settings['active_skin'] ?? 'indigo',
                'customCss' => $settings['custom_css'] ?? '',
                'locale' => $settings['default_locale'] ?? config('app.locale'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'shareUrl' => fn () => $request->session()->get('shareUrl'),
                'shareId' => fn () => $request->session()->get('shareId'),
            ],
        ];
    }
}
