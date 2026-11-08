<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A file or folder a user saved to «المحفوظات». */
class SavedItem extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'savable_type', 'savable_id'];

    public function savable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ids of the given files and folders the user saved, for the bookmark buttons. */
    public static function idsFor(User $user): array
    {
        $rows = static::where('user_id', $user->id)->get(['savable_type', 'savable_id']);

        return [
            'files' => $rows->where('savable_type', (new File)->getMorphClass())->pluck('savable_id')->values()->all(),
            'folders' => $rows->where('savable_type', (new Folder)->getMorphClass())->pluck('savable_id')->values()->all(),
        ];
    }
}
