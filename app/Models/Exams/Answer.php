<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Answer extends Model
{
    protected $table = 'exam_answers';

    protected $fillable = ['exam_attempt_id', 'exam_question_id', 'response', 'flagged', 'points', 'comment', 'checked_at'];

    protected function casts(): array
    {
        return ['response' => 'array', 'flagged' => 'boolean', 'points' => 'float', 'checked_at' => 'datetime'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'exam_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'exam_question_id');
    }
}
