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
            $file->forgetSearchablePdf();
        });
    }

    /**
     * The copy of a scanned PDF with its recognised text laid under the pages (made by OCR).
     * Named after the size so a replaced file never shows an old copy.
     */
    public function searchablePdfPath(): string
    {
        return storage_path('app/searchable/'.$this->id.'-'.$this->size.'.pdf');
    }

    public function searchablePdf(): ?string
    {
        if (strtolower((string) $this->extension) !== 'pdf') {
            return null;
        }
        $path = $this->searchablePdfPath();

        return is_file($path) ? $path : null;
    }

    public function forgetSearchablePdf(): void
    {
        foreach (glob(storage_path('app/searchable/'.$this->id.'-*.pdf')) ?: [] as $old) {
            @unlink($old);
        }
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

    public const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'mkv', 'webm', 'mov', 'avi', 'wmv', 'flv', '3gp', 'mpg', 'mpeg'];

    public const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'opus', 'flac', 'wma'];

    /** By type, or by extension when the upload came with a generic type (application/octet-stream). */
    public function isVideo(): bool
    {
        $mime = (string) $this->mime_type;

        return str_starts_with($mime, 'video/')
            || (! str_starts_with($mime, 'audio/') && in_array(strtolower((string) $this->extension), self::VIDEO_EXTENSIONS, true));
    }

    public function isAudio(): bool
    {
        $mime = (string) $this->mime_type;

        return str_starts_with($mime, 'audio/')
            || (! str_starts_with($mime, 'video/') && in_array(strtolower((string) $this->extension), self::AUDIO_EXTENSIONS, true));
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
