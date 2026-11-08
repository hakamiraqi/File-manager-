<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['file_id', 'timestamp_seconds', 'question', 'order'])]
class VideoQuestion extends Model
{
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function choices(): HasMany
    {
        return $this->hasMany(VideoQuestionChoice::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(VideoQuestionAnswer::class);
    }
}
