<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'role', 'is_active', 'storage_quota_bytes', 'locale', 'theme', 'avatar_path',
    'is_master_admin', 'can_manage_appearance', 'can_manage_users', 'can_manage_translations',
    'can_view_statistics', 'can_manage_settings', 'is_librarian',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['admin', 'editor', 'contributor', 'user'];

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
            $user->storage_used_bytes ??= 0;
            $user->theme ??= 'system';
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Library staff: lends and receives books, manages the catalogue. Admins always can. */
    public function canManageLibrary(): bool
    {
        return $this->isAdmin() || (bool) $this->is_librarian;
    }

    public function isEditor(): bool
    {
        return $this->role === 'editor';
    }

    public function isContributor(): bool
    {
        return $this->role === 'contributor';
    }

    /**
     * Every non-admin role is governed by the admin-configurable permission
     * matrix; admins always pass (they are the superuser role, like VFM's
     * master admin) and every admin capability check below defaults to
     * true for the master admin regardless of the stored flag value.
     */
    public function canPerform(string $action): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return \App\Models\RolePermission::allows($this->role, $action);
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

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    /** Folders an admin assigned to this user (other people's folders they may work in). */
    public function assignedFolders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class, 'folder_user')->withTimestamps();
    }

    private ?array $assignedFolderIdsCache = null;

    /** @return array<int, int> */
    public function assignedFolderIds(): array
    {
        return $this->assignedFolderIdsCache ??= \Illuminate\Support\Facades\DB::table('folder_user')
            ->where('user_id', $this->id)->pluck('folder_id')->map(fn ($id) => (int) $id)->all();
    }

    public function libraryMember(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Library\LibraryMember::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
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
