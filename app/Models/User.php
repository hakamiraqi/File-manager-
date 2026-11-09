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
    'can_view_statistics', 'can_manage_settings', 'is_examiner', 'exam_time_multiplier', 'is_invigilator', 'student_number',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'card_code', 'photo_path'])]
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
            'is_examiner' => 'boolean',
            'is_invigilator' => 'boolean',
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

    /** Invigilators watch exams in the hall; teachers and admins can too. */
    // (Admins and teachers watch the exams they manage; invigilators every exam.)
    public function canInvigilate(): bool
    {
        return $this->canManageExams() || (bool) $this->is_invigilator;
    }

    public function examGroups(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Exams\Group::class, 'exam_group_user', 'user_id', 'exam_group_id');
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

    /** A new login card code (the old card stops working). */
    public function renewCardCode(): string
    {
        $code = 'EX'.strtoupper(\Illuminate\Support\Str::random(22));
        $this->forceFill(['card_code' => $code])->save();

        return $code;
    }

    /** The card code from what a scanner typed or a camera read: the card's address, or the code alone. */
    public static function cardCodeFrom(string $scanned): ?string
    {
        $scanned = trim($scanned);
        if (preg_match('~/card/([A-Za-z0-9]{16,40})~', $scanned, $m)) {
            return strtoupper($m[1]);
        }

        return preg_match('/^EX[A-Za-z0-9]{14,38}$/', $scanned) ? strtoupper($scanned) : null;
    }

    /** The official photo's address, for invigilators and exam staff only (null when there is none). */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('exams.monitor.photo', ['user' => $this->id, 'v' => substr(md5($this->photo_path), 0, 8)]) : null;
    }
}
