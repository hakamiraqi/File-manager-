<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'description', 'ip_address', 'user_agent'])]
class ActivityLog extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (ActivityLog $log) {
            $log->created_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** The file, folder, book… the activity was about (null once deleted). */
    public function subject(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(string $action, ?string $description = null, ?Model $subject = null): self
    {
        return self::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
        ]);
    }
}
