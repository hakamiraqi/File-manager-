<?php

namespace App\Models\Exams;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One marker's mark for a hand-marked answer (double marking). */
class Mark extends Model
{
    protected $table = 'exam_marks';

    protected $fillable = ['exam_answer_id', 'user_id', 'points', 'comment', 'rubric'];

    protected function casts(): array
    {
        return ['points' => 'float', 'rubric' => 'array'];
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class, 'exam_answer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
