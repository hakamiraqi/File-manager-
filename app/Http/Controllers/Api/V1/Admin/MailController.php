<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Mail\EventNotificationMail;
use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class MailController extends Controller
{
    private const DEFAULTS = [
        'mail_host' => '',
        'mail_port' => '587',
        'mail_username' => '',
        'mail_password' => '',
        'mail_encryption' => 'tls',
        'mail_from_address' => '',
        'mail_from_name' => '',
        'notify_upload' => '0',
        'notify_download' => '0',
        'notify_login' => '0',
        'notify_newfolder' => '0',
        'notify_registration' => '0',
    ];

    public function index(): JsonResponse
    {
        $settings = array_merge(self::DEFAULTS, Setting::all_settings());
        unset($settings['mail_password']);

        return response()->json(['settings' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mail_host' => ['nullable', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_encryption' => ['nullable', 'in:tls,ssl,none'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            'notify_upload' => ['boolean'],
            'notify_download' => ['boolean'],
            'notify_login' => ['boolean'],
            'notify_newfolder' => ['boolean'],
            'notify_registration' => ['boolean'],
        ]);

        foreach (['notify_upload', 'notify_download', 'notify_login', 'notify_newfolder', 'notify_registration'] as $field) {
            $data[$field] = $request->boolean($field) ? '1' : '0';
        }

        if (empty($data['mail_password'])) {
            unset($data['mail_password']);
        }

        Setting::setMany($data);

        ActivityLog::record('admin.mail_settings_updated', 'حدّث إعدادات البريد الإلكتروني (SMTP) والإشعارات');

        $settings = array_merge(self::DEFAULTS, Setting::all_settings());
        unset($settings['mail_password']);

        return response()->json(['settings' => $settings]);
    }

    public function sendTest(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        Mail::to($data['email'])->send(new EventNotificationMail(
            'رسالة اختبار من '.config('app.name'),
            'إذا وصلتك هذه الرسالة فإعدادات SMTP تعمل بشكل صحيح.',
        ));

        return response()->json(['message' => 'تم إرسال رسالة الاختبار.']);
    }
}
