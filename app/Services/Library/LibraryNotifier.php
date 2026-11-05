<?php

namespace App\Services\Library;

use App\Mail\EventNotificationMail;
use App\Models\Library\LibraryMember;
use App\Models\Library\Notice;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Tells a reader something: a notice on the site ("استعاراتي") and an e-mail when they have one. */
class LibraryNotifier
{
    public function notify(LibraryMember $member, string $kind, string $subject, string $message): void
    {
        Notice::create(['member_id' => $member->id, 'kind' => $kind, 'message' => $message]);

        $email = $member->email ?: $member->user?->email;
        if ($email) {
            $this->mail($email, $subject, $message);
        }
    }

    /** The daily summary for library staff (librarians; admins when there are none). */
    public function staff(string $subject, string $message): void
    {
        $staff = User::where('is_active', true)->where('is_librarian', true)->pluck('email');
        if ($staff->isEmpty()) {
            $staff = User::where('is_active', true)->where('role', 'admin')->pluck('email');
        }
        foreach ($staff as $email) {
            $this->mail($email, $subject, $message);
        }
    }

    private function mail(string $email, string $subject, string $message): void
    {
        try {
            Mail::to($email)->queue(new EventNotificationMail($subject, $message));
        } catch (\Throwable $e) {
            Log::warning("Library mail to {$email} failed: {$e->getMessage()}");
        }
    }
}
