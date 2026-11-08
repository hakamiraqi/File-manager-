<?php

namespace App\Models\Exams;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student asks for a question's mark to be looked at again. */
class Appeal extends Model
{
    protected $table = 'exam_appeals';

    protected $fillable = ['exam_attempt_id', 'exam_question_id', 'body', 'status', 'reply', 'replied_by', 'replied_at'];

    protected function casts(): array
    {
        return ['replied_at' => 'datetime'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class, 'exam_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'exam_question_id');
    }

    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }
}
