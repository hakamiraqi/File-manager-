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
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'role', 'is_active', 'storage_quota_bytes', 'locale', 'theme', 'avatar_path',
    'is_master_admin', 'can_manage_appearance', 'can_manage_users', 'can_manage_translations',
    'can_view_statistics', 'can_manage_settings', 'is_librarian', 'is_examiner', 'exam_time_multiplier',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['admin', 'user'];

    /** The profile picture's address, shown in the header and on the profile page. */
    protected $appends = ['avatar_url'];

    protected function avatarUrl(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::get(
            fn () => $this->avatar_path ? asset('storage/'.$this->avatar_path) : null
        );
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'is_master_admin' => 'boolean',
            'can_manage_appearance' => 'boolean',
            'can_manage_users' => 'boolean',
            'can_manage_translations' => 'boolean',
            'can_view_statistics' => 'boolean',
            'can_manage_settings' => 'boolean',
            'is_librarian' => 'boolean',
            'is_examiner' => 'boolean',
            'exam_time_multiplier' => 'float',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
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
            $user->theme ??= 'system';
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** A teacher: writes exams, marks them and sees the results. Admins always can. */
    public function canManageExams(): bool
    {
        return $this->isAdmin() || (bool) $this->is_examiner;
    }

    public function hasAdminCapability(string $capability): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        if ($this->is_master_admin) {
            return true;
        }

        return (bool) $this->getAttribute($capability);
    }

    public function oauthAccounts(): HasMany
    {
        return $this->hasMany(OauthAccount::class);
    }

    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return ! is_null($this->two_factor_secret) && ! is_null($this->two_factor_confirmed_at);
    }

    /**
     * @return array<int, string>
     */
    public function recoveryCodes(): array
    {
        return $this->two_factor_recovery_codes ?? [];
    }

    /**
     * A recovery code is single-use: once it authenticates a login it is
     * removed so it can never be replayed.
     */
    public function consumeRecoveryCode(string $code): void
    {
        $this->forceFill([
            'two_factor_recovery_codes' => collect($this->recoveryCodes())
                ->reject(fn (string $stored) => hash_equals($stored, $code))
                ->values()
                ->all(),
        ])->save();
    }
}
