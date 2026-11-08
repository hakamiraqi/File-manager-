<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'shareable_type', 'shareable_id', 'token', 'short_code', 'password',
    'allow_download', 'max_downloads', 'one_time_download', 'expires_at', 'revoked_at',
])]
#[Hidden(['password'])]
class Share extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'allow_download' => 'boolean',
            'one_time_download' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Share $share) {
            $share->token ??= Str::random(40);
            $share->short_code ??= self::generateUniqueShortCode();
        });
    }

    public static function generateUniqueShortCode(): string
    {
        do {
            $code = Str::lower(Str::random(7));
        } while (self::where('short_code', $code)->exists());

        return $code;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    public function emails(): HasMany
    {
        return $this->hasMany(ShareEmail::class);
    }

    public function isExpired(): bool
    {
        return ($this->expires_at !== null && $this->expires_at->isPast()) || $this->isRevoked();
    }

    public function isDownloadLimitReached(): bool
    {
        return $this->max_downloads !== null && $this->download_count >= $this->max_downloads;
    }

    public function isPasswordProtected(): bool
    {
        return ! empty($this->password);
    }
}
