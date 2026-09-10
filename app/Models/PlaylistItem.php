<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['playlist_id', 'file_id', 'order'])]
class PlaylistItem extends Model
{
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
