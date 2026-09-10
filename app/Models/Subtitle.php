<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['file_id', 'language', 'label', 'path'])]
class Subtitle extends Model
{
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
