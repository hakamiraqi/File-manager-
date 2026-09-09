<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'storage_quota_bytes', 'locale', 'theme', 'avatar_path'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            // Populate defaults in-memory (not just at the DB-schema level)
            // so a freshly created instance behaves correctly without a
            // round-trip re-fetch (e.g. Auth::login() right after create()).
            $user->role ??= 'user';
            $user->is_active ??= true;
            $user->storage_used_bytes ??= 0;
            $user->theme ??= 'system';
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function quotaBytes(): int
    {
        return $this->storage_quota_bytes ?? (config('filenest.default_quota_mb') * 1024 * 1024);
    }

    public function storageUsagePercent(): float
    {
        $quota = $this->quotaBytes();

        return $quota > 0 ? min(100, round(($this->storage_used_bytes / $quota) * 100, 1)) : 0;
    }
}
