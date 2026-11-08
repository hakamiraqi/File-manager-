<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the browser reported during an attempt: the tab hidden, the window left, a paste... */
class Event extends Model
{
    public const TYPES = ['hidden', 'blur', 'paste', 'fullscreen_exit', 'ip_changed', 'offline_file'];

    public const UPDATED_AT = null;

    protected $table = 'exam_events';

    protected $fillable = ['exam_attempt_id', 'type', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'exam_attempt_id');
    }
}
