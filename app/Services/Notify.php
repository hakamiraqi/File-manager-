<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/** Sends in-app notifications (the bell in the top bar). Never breaks the action that triggers it. */
class Notify
{
    /**
     * @param  User|iterable<User>|int|null  $users
     */
    public static function to(mixed $users, string $category, string $kind, string $title, ?string $body = null, ?string $url = null, ?User $except = null): void
    {
        $list = match (true) {
            $users === null => collect(),
            $users instanceof User => collect([$users]),
            is_int($users) => User::whereKey($users)->get(),
            default => collect($users),
        };

        foreach ($list->filter()->unique('id') as $user) {
            if ($except && $user->id === $except->id) {
                continue;
            }
            try {
                UserNotification::create([
                    'user_id' => $user->id,
                    'category' => $category,
                    'kind' => $kind,
                    'title' => mb_substr($title, 0, 190),
                    'body' => $body,
                    'url' => $url,
                ]);
            } catch (\Throwable $e) {
                Log::warning("Notification for user {$user->id} failed: {$e->getMessage()}");
            }
        }
    }

    /** Librarians, or the admins when there are no librarians. */
    public static function libraryStaff(): Collection
    {
        $staff = User::where('is_active', true)->where('is_librarian', true)->get();

        return $staff->isNotEmpty() ? $staff : User::where('is_active', true)->where('role', 'admin')->get();
    }

    /** Active admins who hold an admin capability (the master admin holds all). */
    public static function admins(string $capability): Collection
    {
        return User::where('is_active', true)->where('role', 'admin')->get()
            ->filter(fn (User $u) => $u->hasAdminCapability($capability));
    }
}
