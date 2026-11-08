<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use Translatable;

    public const SINGLE = 'single';           // one correct choice
    public const MULTIPLE = 'multiple';       // several correct choices, partial credit
    public const TRUE_FALSE = 'true_false';   // two choices: صح / خطأ
    public const SHORT = 'short';             // a word or phrase, compared with the accepted answers
    public const NUMERIC = 'numeric';         // a number within a tolerance
    public const MATCHING = 'matching';       // each item goes with one of the targets
    public const ORDERING = 'ordering';       // put the items in the right order
    public const ESSAY = 'essay';             // marked by hand
    public const DESCRIPTION = 'description'; // text or media for the questions after it, no mark

    public const TYPES = [
        self::SINGLE, self::MULTIPLE, self::TRUE_FALSE, self::SHORT, self::NUMERIC,
        self::MATCHING, self::ORDERING, self::ESSAY, self::DESCRIPTION,
    ];

    /** Types whose answer is picked from the choices. */
    public const CHOICE_TYPES = [self::SINGLE, self::MULTIPLE, self::TRUE_FALSE, self::MATCHING, self::ORDERING];

    protected $table = 'exam_questions';

    protected $fillable = ['exam_id', 'type', 'body', 'feedback', 'points', 'settings', 'position'];

    protected function casts(): array
    {
        return ['body' => 'array', 'feedback' => 'array', 'settings' => 'array', 'points' => 'float', 'position' => 'integer'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function choices(): HasMany
    {
        return $this->hasMany(Choice::class, 'exam_question_id')->orderBy('position')->orderBy('id');
    }

    public function isGraded(): bool
    {
        return $this->type !== self::DESCRIPTION;
    }
}
