<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Answer extends Model
{
    protected $table = 'exam_answers';

    protected $fillable = ['exam_attempt_id', 'exam_question_id', 'response', 'flagged', 'points', 'comment', 'checked_at', 'rubric'];

    public function marks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Mark::class, 'exam_answer_id')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['response' => 'array', 'flagged' => 'boolean', 'points' => 'float', 'checked_at' => 'datetime', 'rubric' => 'array'];
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
