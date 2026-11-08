<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['file_id', 'user_id', 'parent_id', 'body', 'timestamp_seconds'])]
class VideoComment extends Model
{
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(VideoComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(VideoComment::class, 'parent_id');
    }
}
