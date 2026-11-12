<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\IpRule;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SecurityController extends Controller
{
    private const DEFAULTS = [
        'guest_access_enabled' => '0',
        'public_files_enabled' => '1',
        'public_library_enabled' => '1',
        'public_theses_enabled' => '1',
        'contact_enabled' => '1',
        'show_captcha_register' => '0',
        'show_captcha_reset' => '0',
        'show_captcha_download' => '0',
        'recaptcha_enabled' => '0',
        'recaptcha_invisible' => '0',
        'recaptcha_site_key' => '',
        'recaptcha_secret_key' => '',
        'ip_filter_mode' => 'none',
        'ip_redirect_url' => '',
        'oauth_google_client_id' => '',
        'oauth_google_client_secret' => '',
        'oauth_github_client_id' => '',
        'oauth_github_client_secret' => '',
        'oauth_linkedin_client_id' => '',
        'oauth_linkedin_client_secret' => '',
        'remote_upload_enabled' => '0',
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'settings' => array_merge(self::DEFAULTS, Setting::visibleSettings()),
            'ip_rules' => IpRule::orderBy('type')->orderBy('id')->get(),
            'app_url' => config('app.url'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'guest_access_enabled' => ['boolean'],
            'public_files_enabled' => ['sometimes', 'boolean'],
            'public_library_enabled' => ['sometimes', 'boolean'],
            'public_theses_enabled' => ['sometimes', 'boolean'],
            'contact_enabled' => ['sometimes', 'boolean'],
            'show_captcha_register' => ['boolean'],
            'show_captcha_reset' => ['boolean'],
            'show_captcha_download' => ['boolean'],
            'recaptcha_enabled' => ['boolean'],
            'recaptcha_invisible' => ['boolean'],
            'recaptcha_site_key' => ['nullable', 'string', 'max:255'],
            'recaptcha_secret_key' => ['nullable', 'string', 'max:255'],
            'ip_filter_mode' => ['required', 'in:none,blacklist,whitelist'],
            'ip_redirect_url' => ['nullable', 'url', 'max:255'],
            'oauth_google_client_id' => ['nullable', 'string', 'max:255'],
            'oauth_google_client_secret' => ['nullable', 'string', 'max:255'],
            'oauth_github_client_id' => ['nullable', 'string', 'max:255'],
            'oauth_github_client_secret' => ['nullable', 'string', 'max:255'],
            'oauth_linkedin_client_id' => ['nullable', 'string', 'max:255'],
            'oauth_linkedin_client_secret' => ['nullable', 'string', 'max:255'],
            'remote_upload_enabled' => ['boolean'],
        ]);

        // Older apps do not send the visitors' site switches: leave those as they are.
        foreach (['public_files_enabled', 'public_library_enabled', 'public_theses_enabled', 'contact_enabled'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->boolean($field) ? '1' : '0';
            }
        }
        foreach (['guest_access_enabled', 'show_captcha_register', 'show_captcha_reset', 'show_captcha_download', 'recaptcha_enabled', 'recaptcha_invisible', 'remote_upload_enabled'] as $boolField) {
            $data[$boolField] = $request->boolean($boolField) ? '1' : '0';
        }

        Setting::setMany($data);

        ActivityLog::record('admin.security_updated', 'حدّث إعدادات الأمان وتسجيل الدخول الخارجي');

        return response()->json(['settings' => array_merge(self::DEFAULTS, Setting::visibleSettings())]);
    }

    public function storeIpRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:blacklist,whitelist'],
            'ip_or_cidr' => ['required', 'string', 'max:45'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $rule = IpRule::create($data);
        Cache::forget('ip_rules');

        ActivityLog::record('admin.ip_rule_added', "أضاف قاعدة IP: {$data['ip_or_cidr']}");

        return response()->json(['ip_rule' => $rule], 201);
    }

    public function destroyIpRule(IpRule $ipRule): JsonResponse
    {
        $ipRule->delete();
        Cache::forget('ip_rules');

        ActivityLog::record('admin.ip_rule_removed', "حذف قاعدة IP: {$ipRule->ip_or_cidr}");

        return response()->json(['message' => 'تم حذف القاعدة.']);
    }
}
