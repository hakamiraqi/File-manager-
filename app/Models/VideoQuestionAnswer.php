<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['video_question_id', 'user_id', 'video_question_choice_id', 'is_correct'])]
class VideoQuestionAnswer extends Model
{
    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(VideoQuestion::class, 'video_question_id');
    }

    public function choice(): BelongsTo
    {
        return $this->belongsTo(VideoQuestionChoice::class, 'video_question_choice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
