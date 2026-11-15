<?php

namespace App\Models\Library;

use App\Jobs\KohaPushBook;
use App\Models\File;
use App\Services\Library\DoiLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Book extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'section_id', 'title', 'subtitle', 'authors', 'isbn', 'publisher', 'year', 'language',
        'edition', 'pages', 'description', 'cover_path', 'file_id', 'reference_only', 'created_by',
        'call_number', 'subjects', 'marc_control', 'marc_xml', 'koha_biblio_id', 'doi', 'journal', 'volume', 'issue', 'page_range',
    ];

    /** The full MARC record is for export only, never sent to pages or the app. */
    protected $hidden = ['marc_xml'];

    protected $appends = ['cover_url'];

    protected function casts(): array
    {
        return ['reference_only' => 'boolean', 'pages' => 'integer', 'cover_checked_at' => 'datetime'];
    }

    /** What a catalogue record is made of: a change here is sent to Koha when Koha runs the library. */
    public const CATALOGUE_FIELDS = ['title', 'subtitle', 'authors', 'isbn', 'publisher', 'year', 'language', 'edition', 'pages', 'description', 'call_number', 'subjects', 'marc_xml', 'doi', 'journal', 'volume', 'issue', 'page_range'];

    protected static function booted(): void
    {
        static::saved(function (self $book) {
            if ($book->wasRecentlyCreated) {
                KohaPushBook::afterSave($book);
            } elseif ($book->koha_biblio_id && $book->wasChanged(self::CATALOGUE_FIELDS)) {
                KohaPushBook::afterSave($book, true);
            }
        });
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(LibrarySection::class, 'section_id');
    }

    public function copies(): HasMany
    {
        return $this->hasMany(BookCopy::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover_path ? route('library.cover', ['type' => 'book', 'id' => $this->id, 'v' => $this->updated_at?->timestamp]) : null;
    }

    /** Adds `copies_count` and `available_count` to a query. */
    public function scopeWithAvailability($query)
    {
        return $query->withCount([
            'copies',
            'copies as available_count' => fn ($q) => $q->where('status', BookCopy::AVAILABLE),
        ]);
    }

    public function scopeSearch($query, string $term)
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        // A pasted DOI (or a doi.org link) finds its article.
        if ($doi = DoiLookup::normalize($term)) {
            return $query->where('doi', $doi);
        }

        return $query->where(fn ($q) => $q
            ->where('title', 'like', "%{$term}%")
            ->orWhere('journal', 'like', "%{$term}%")
            ->orWhere('subtitle', 'like', "%{$term}%")
            ->orWhere('authors', 'like', "%{$term}%")
            ->orWhere('publisher', 'like', "%{$term}%")
            ->orWhere('isbn', str_replace('-', '', $term))
            ->orWhereHas('copies', fn ($c) => $c->where('barcode', $term)->orWhere('rfid_tag', $term)));
    }
}
