<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    protected $table = 'library_notices';

    protected $fillable = ['member_id', 'kind', 'message', 'read_at'];

    public function member(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'member_id');
    }

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
