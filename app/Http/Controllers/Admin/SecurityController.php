<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\IpRule;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

    public function index(): Response
    {
        return Inertia::render('Admin/Security', [
            'settings' => array_merge(self::DEFAULTS, Setting::all_settings()),
            'ipRules' => IpRule::orderBy('type')->orderBy('id')->get(),
            'appUrl' => config('app.url'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'guest_access_enabled' => ['boolean'],
            'public_files_enabled' => ['boolean'],
            'public_library_enabled' => ['boolean'],
            'public_theses_enabled' => ['boolean'],
            'contact_enabled' => ['boolean'],
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

        foreach (['guest_access_enabled', 'public_files_enabled', 'public_library_enabled', 'public_theses_enabled', 'contact_enabled', 'show_captcha_register', 'show_captcha_reset', 'show_captcha_download', 'recaptcha_enabled', 'recaptcha_invisible', 'remote_upload_enabled'] as $boolField) {
            $data[$boolField] = $request->boolean($boolField) ? '1' : '0';
        }

        Setting::setMany($data);

        ActivityLog::record('admin.security_updated', 'حدّث إعدادات الأمان وتسجيل الدخول الخارجي');

        return back()->with('success', 'تم حفظ إعدادات الأمان.');
    }

    public function storeIpRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:blacklist,whitelist'],
            'ip_or_cidr' => ['required', 'string', 'max:45'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        IpRule::create($data);
        \Illuminate\Support\Facades\Cache::forget('ip_rules');

        ActivityLog::record('admin.ip_rule_added', "أضاف قاعدة IP: {$data['ip_or_cidr']}");

        return back()->with('success', 'تمت إضافة القاعدة.');
    }

    public function destroyIpRule(IpRule $ipRule): RedirectResponse
    {
        $ipRule->delete();
        \Illuminate\Support\Facades\Cache::forget('ip_rules');

        ActivityLog::record('admin.ip_rule_removed', "حذف قاعدة IP: {$ipRule->ip_or_cidr}");

        return back()->with('success', 'تم حذف القاعدة.');
    }
}
