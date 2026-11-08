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
        'site_name' => 'نظام الامتحانات',
        'logo_path' => null,
        'header_image_path' => null,
        'registration_enabled' => '0',
        'default_locale' => 'ar',
        'active_skin' => 'indigo',
        'custom_css' => '',
        'institution_university' => '',
        'institution_college' => '',
        'work_day_start' => '08:00',
    ];

    public function index(): Response
    {
        $settings = array_merge(self::DEFAULTS, Setting::all_settings());

        return Inertia::render('Admin/Settings', [
            'settings' => $settings,
            'skins' => config('filenest.skins'),
            'locales' => \App\Support\Locales::active(),
            'roles' => \App\Models\User::ROLES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'registration_enabled' => ['boolean'],
            'default_locale' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Support\Locales::active()))],
            'active_skin' => ['required', 'string', 'in:'.implode(',', config('filenest.skins')).',custom'],
            'custom_css' => ['nullable', 'string', 'max:20000'],
            'institution_university' => ['nullable', 'string', 'max:255'],
            'institution_college' => ['nullable', 'string', 'max:255'],
            'work_day_start' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'logo' => ['nullable', 'image', 'max:1024'],
            'header_image' => ['nullable', 'image', 'max:2048'],
        ]);

        $user = $request->user();
        $canAppearance = $user->hasAdminCapability('can_manage_appearance');
        $canSettings = $user->hasAdminCapability('can_manage_settings');
        $appearanceFields = ['site_name', 'active_skin', 'custom_css', 'logo_path', 'header_image_path'];

        if ($request->hasFile('logo') && $canAppearance) {
            $data['logo_path'] = $request->file('logo')->store('branding', 'public');
        }

        if ($request->hasFile('header_image') && $canAppearance) {
            $data['header_image_path'] = $request->file('header_image')->store('branding', 'public');
        }

        foreach (['registration_enabled'] as $boolField) {
            $data[$boolField] = $request->boolean($boolField) ? '1' : '0';
        }

        unset($data['logo'], $data['header_image']);

        if (! $canSettings) {
            $data = array_intersect_key($data, array_flip($appearanceFields));
        } elseif (! $canAppearance) {
            $data = array_diff_key($data, array_flip($appearanceFields));
        }

        Setting::setMany($data);

        ActivityLog::record('admin.settings_updated', 'حدّث إعدادات النظام العامة');

        return back()->with('success', 'تم حفظ الإعدادات.');
    }
}
