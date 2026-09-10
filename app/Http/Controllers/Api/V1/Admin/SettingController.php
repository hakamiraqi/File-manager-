<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    private const DEFAULTS = [
        'site_name' => 'FileNest',
        'logo_path' => null,
        'header_image_path' => null,
        'registration_enabled' => '1',
        'registration_role' => 'user',
        'default_locale' => 'ar',
        'active_skin' => 'indigo',
        'custom_css' => '',
        'default_quota_mb' => '5120',
        'max_upload_mb' => '2048',
        'extension_filter_mode' => 'reject',
        'extension_list' => '',
        'max_zip_files' => '2000',
        'enable_pretty_links' => '0',
        'global_search' => '1',
        'content_indexing_enabled' => '1',
        'content_indexing_max_mb' => '25',
        'thumbnails_enabled' => '1',
        'thumbnail_max_px' => '400',
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'settings' => array_merge(self::DEFAULTS, Setting::all_settings()),
            'skins' => config('filenest.skins'),
            'locales' => config('filenest.supported_locales'),
            'roles' => \App\Models\User::ROLES,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:100'],
            'registration_enabled' => ['boolean'],
            'registration_role' => ['required', 'in:user,editor,contributor'],
            'default_locale' => ['required', 'in:ar,en'],
            'active_skin' => ['required', 'string', 'in:'.implode(',', config('filenest.skins')).',custom'],
            'custom_css' => ['nullable', 'string', 'max:20000'],
            'default_quota_mb' => ['required', 'integer', 'min:1'],
            'max_upload_mb' => ['required', 'integer', 'min:1'],
            'extension_filter_mode' => ['required', 'in:reject,allow'],
            'extension_list' => ['nullable', 'string', 'max:2000'],
            'max_zip_files' => ['required', 'integer', 'min:1'],
            'enable_pretty_links' => ['boolean'],
            'global_search' => ['boolean'],
            'content_indexing_enabled' => ['boolean'],
            'content_indexing_max_mb' => ['required', 'integer', 'min:1', 'max:500'],
            'thumbnails_enabled' => ['boolean'],
            'thumbnail_max_px' => ['required', 'integer', 'min:50', 'max:2000'],
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

        foreach (['registration_enabled', 'enable_pretty_links', 'global_search', 'content_indexing_enabled', 'thumbnails_enabled'] as $boolField) {
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

        return response()->json(['settings' => array_merge(self::DEFAULTS, Setting::all_settings())]);
    }
}
