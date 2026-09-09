<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    private const DEFAULTS = [
        'site_name' => 'FileNest',
        'logo_path' => null,
        'header_image_path' => null,
        'registration_enabled' => '1',
        'default_locale' => 'ar',
        'active_skin' => 'indigo',
        'custom_css' => '',
        'default_quota_mb' => '5120',
        'max_upload_mb' => '2048',
    ];

    public function index(): Response
    {
        $settings = array_merge(self::DEFAULTS, Setting::all_settings());

        return Inertia::render('Admin/Settings', [
            'settings' => $settings,
            'skins' => config('filenest.skins'),
            'locales' => config('filenest.supported_locales'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'registration_enabled' => ['boolean'],
            'default_locale' => ['required', 'in:ar,en'],
            'active_skin' => ['required', 'string', 'in:'.implode(',', config('filenest.skins')).',custom'],
            'custom_css' => ['nullable', 'string', 'max:20000'],
            'default_quota_mb' => ['required', 'integer', 'min:1'],
            'max_upload_mb' => ['required', 'integer', 'min:1'],
            'logo' => ['nullable', 'image', 'max:1024'],
            'header_image' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('branding', 'public');
        }

        if ($request->hasFile('header_image')) {
            $data['header_image_path'] = $request->file('header_image')->store('branding', 'public');
        }

        $data['registration_enabled'] = $request->boolean('registration_enabled') ? '1' : '0';

        unset($data['logo'], $data['header_image']);

        Setting::setMany($data);

        ActivityLog::record('admin.settings_updated', 'حدّث إعدادات النظام العامة');

        return back()->with('success', 'تم حفظ الإعدادات.');
    }
}
