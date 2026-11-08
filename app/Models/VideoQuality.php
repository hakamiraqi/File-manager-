<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['file_id', 'height', 'path', 'size'])]
class VideoQuality extends Model
{
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
