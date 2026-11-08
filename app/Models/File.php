<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable(['user_id', 'folder_id', 'name', 'disk', 'path', 'extension', 'mime_type', 'size', 'thumbnail_path'])]
#[Hidden(['content_text'])]
class File extends Model
{
    use HasFactory, SoftDeletes;

    protected $appends = ['human_size', 'is_indexed', 'video_qualities'];

    protected function casts(): array
    {
        return [
            'content_indexed_at' => 'datetime',
            'ocr_done_at' => 'datetime',
        ];
    }

    /**
     * Subtitle tracks and transcoded quality variants each have their own
     * bytes on disk that the DB's cascadeOnDelete doesn't touch. Cleaning
     * them up here (rather than in every controller that force-deletes a
     * file) means no purge path — trash, admin, the artisan command — can
     * forget it, the way the original thumbnail cleanup once did.
     */
    protected static function booted(): void
    {
        static::forceDeleting(function (File $file) {
            foreach ($file->subtitles as $subtitle) {
                $file->disk()->delete($subtitle->path);
            }
            foreach ($file->qualities as $quality) {
                $file->disk()->delete($quality->path);
            }
        });
    }

    protected function humanSize(): Attribute
    {
        return Attribute::get(fn () => $this->formatSize($this->size));
    }

    protected function isIndexed(): Attribute
    {
        return Attribute::get(fn () => $this->content_indexed_at !== null && $this->content_text !== null);
    }

    /**
     * Alternate-resolution variants for video playback (see VideoTranscoder).
     * Deliberately only resolved when the `qualities` relation is already
     * eager-loaded, so a page that never asked for it doesn't pay for an
     * extra query per file.
     */
    protected function videoQualities(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->relationLoaded('qualities')) {
                return [];
            }

            return $this->qualities
                ->sortByDesc('height')
                ->map(fn (VideoQuality $q) => [
                    'height' => $q->height,
                    'src' => route('files.preview-quality', [$this->id, $q->height]),
                ])
                ->values()
                ->all();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function shares(): MorphMany
    {
        return $this->morphMany(Share::class, 'shareable');
    }

    public function subtitles(): HasMany
    {
        return $this->hasMany(Subtitle::class);
    }

    public function qualities(): HasMany
    {
        return $this->hasMany(VideoQuality::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(VideoQuestion::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(VideoComment::class);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mime_type, 'video/');
    }

    public function isAudio(): bool
    {
        return str_starts_with((string) $this->mime_type, 'audio/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function isPreviewable(): bool
    {
        return $this->isImage() || $this->isVideo() || $this->isPdf()
            || in_array($this->mime_type, ['text/plain', 'text/csv', 'application/json'], true);
    }

    public function disk()
    {
        return Storage::disk($this->disk);
    }

    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
