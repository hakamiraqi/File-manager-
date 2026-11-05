<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookCopy extends Model
{
    public const AVAILABLE = 'available';
    public const LOANED = 'loaned';
    public const ON_HOLD = 'on_hold';
    public const DAMAGED = 'damaged';
    public const LOST = 'lost';

    public const STATUSES = [self::AVAILABLE, self::LOANED, self::ON_HOLD, self::DAMAGED, self::LOST];

    protected $fillable = ['book_id', 'barcode', 'rfid_tag', 'shelf', 'status', 'condition', 'notes'];

    protected static function booted(): void
    {
        // A copy added without a barcode gets the next one: LIB000001, LIB000002...
        static::created(function (self $copy) {
            if (str_starts_with($copy->barcode, 'tmp-')) {
                $copy->forceFill(['barcode' => sprintf('LIB%06d', $copy->id)])->saveQuietly();
            }
        });
    }

    public static function newBarcodePlaceholder(): string
    {
        return 'tmp-'.\Illuminate\Support\Str::uuid();
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** A scanned code: barcode or RFID tag, as typed by a keyboard-wedge reader. */
    public static function findByCode(string $code): ?self
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return self::where('barcode', $code)->orWhere('rfid_tag', $code)->first();
    }
}
