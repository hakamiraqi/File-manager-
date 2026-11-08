<?php

namespace App\Models\Exams;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One student's exceptions on one exam: extra minutes, a later close, more attempts. */
class Override extends Model
{
    protected $table = 'exam_overrides';

    protected $fillable = ['exam_id', 'user_id', 'extra_minutes', 'closes_at', 'max_attempts', 'reason'];

    protected function casts(): array
    {
        return ['closes_at' => 'datetime', 'extra_minutes' => 'integer', 'max_attempts' => 'integer'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
