<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class StorageQuotaService
{
    public function assertHasSpace(User $user, int $additionalBytes): void
    {
        if ($user->storage_used_bytes + $additionalBytes > $user->quotaBytes()) {
            throw ValidationException::withMessages([
                'file' => 'لا توجد مساحة تخزين كافية لإتمام هذا الرفع. الرجاء تحرير مساحة أو التواصل مع الإدارة.',
            ]);
        }
    }

    public function increment(User $user, int $bytes): void
    {
        $user->increment('storage_used_bytes', $bytes);
    }

    public function decrement(User $user, int $bytes): void
    {
        $user->decrement('storage_used_bytes', min($bytes, $user->storage_used_bytes));
    }
}
