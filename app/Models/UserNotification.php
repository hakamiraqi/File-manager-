<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'category', 'kind', 'title', 'body', 'url', 'read_at'])]
class UserNotification extends Model
{
    public const CATEGORIES = ['library', 'files', 'account', 'admin'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'has_url' => (bool) $this->url,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public static function unreadCount(?User $user): int
    {
        if (! $user) {
            return 0;
        }
        try {
            return self::where('user_id', $user->id)->whereNull('read_at')->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
