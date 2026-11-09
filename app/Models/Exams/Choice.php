<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Choice extends Model
{
    use Translatable;

    protected $table = 'exam_choices';

    protected $fillable = ['exam_question_id', 'body', 'match', 'is_correct', 'position', 'image'];

    protected function casts(): array
    {
        return ['body' => 'array', 'match' => 'array', 'is_correct' => 'boolean', 'position' => 'integer'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'exam_question_id');
    }
}
