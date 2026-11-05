<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hold extends Model
{
    public const WAITING = 'waiting';     // in the queue, no copy yet
    public const READY = 'ready';         // a copy is set aside until expires_at
    public const FULFILLED = 'fulfilled'; // the reader took it
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    protected $table = 'library_holds';

    protected $fillable = ['book_id', 'member_id', 'copy_id', 'status', 'ready_at', 'expires_at'];

    protected function casts(): array
    {
        return ['ready_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'member_id');
    }

    public function copy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class, 'copy_id');
    }

    public function scopeOpen($q)
    {
        return $q->whereIn('status', [self::WAITING, self::READY]);
    }
}
