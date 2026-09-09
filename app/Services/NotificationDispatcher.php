<?php

namespace App\Services;

use App\Mail\EventNotificationMail;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the admin-facing event e-mails governed by the "notify_*" settings
 * (notify_upload, notify_download, notify_login, notify_newfolder,
 * notify_registration), mirroring VFM's per-event notification toggles.
 * Every admin with an active account receives these; there is no per-user
 * subscription concept, matching the original product.
 */
class NotificationDispatcher
{
    public function uploadOccurred(User $owner, string $fileName): void
    {
        $this->maybeNotify('notify_upload', "رفع {$owner->name} الملف \"{$fileName}\"", 'إشعار رفع ملف');
    }

    public function downloadOccurred(User $owner, string $fileName): void
    {
        $this->maybeNotify('notify_download', "تم تحميل الملف \"{$fileName}\" الخاص بـ {$owner->name}", 'إشعار تحميل ملف');
    }

    public function newFolderOccurred(User $owner, string $folderName): void
    {
        $this->maybeNotify('notify_newfolder', "أنشأ {$owner->name} مجلداً جديداً \"{$folderName}\"", 'إشعار إنشاء مجلد');
    }

    public function loginOccurred(User $user): void
    {
        $this->maybeNotify('notify_login', "سجّل {$user->name} ({$user->email}) الدخول إلى النظام", 'إشعار تسجيل دخول');
    }

    public function registrationOccurred(User $user): void
    {
        $this->maybeNotify('notify_registration', "انضم مستخدم جديد: {$user->name} ({$user->email})", 'إشعار مستخدم جديد');
    }

    private function maybeNotify(string $settingKey, string $message, string $subject): void
    {
        if (! Setting::get($settingKey, false)) {
            return;
        }

        $admins = User::where('role', 'admin')->where('is_active', true)->get(['id', 'email']);

        foreach ($admins as $admin) {
            Mail::to($admin->email)->queue(new EventNotificationMail($subject, $message));
        }
    }
}
