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
    public const CLOZE = 'cloze';             // blanks in the text: typed, or picked from a list
    public const ESSAY = 'essay';             // marked by hand
    public const DESCRIPTION = 'description'; // text or media for the questions after it, no mark
    public const RANDOM = 'random';           // a slot: N questions drawn from a bank category per student

    public const TYPES = [
        self::SINGLE, self::MULTIPLE, self::TRUE_FALSE, self::SHORT, self::NUMERIC,
        self::MATCHING, self::ORDERING, self::CLOZE, self::ESSAY, self::DESCRIPTION, self::RANDOM,
    ];

    /** Types whose answer is picked from the choices. */
    public const CHOICE_TYPES = [self::SINGLE, self::MULTIPLE, self::TRUE_FALSE, self::MATCHING, self::ORDERING];

    protected $table = 'exam_questions';

    protected $fillable = ['exam_id', 'bank_category_id', 'type', 'body', 'feedback', 'points', 'settings', 'position'];

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

    public function category(): BelongsTo
    {
        return $this->belongsTo(BankCategory::class, 'bank_category_id');
    }

    /** A question that carries marks (not a text, not a random slot). */
    public function isGraded(): bool
    {
        return ! in_array($this->type, [self::DESCRIPTION, self::RANDOM], true);
    }

    /** Points a random slot is worth in all: each drawn question × how many. */
    public function slotPoints(): float
    {
        return $this->type === self::RANDOM ? $this->points * (int) ($this->settings['count'] ?? 0) : $this->points;
    }

    /** A copy of this question (and its choices) for an exam or a bank category. */
    public function copyTo(?int $examId, ?int $categoryId, int $position = 0): self
    {
        $copy = $this->replicate(['exam_id', 'bank_category_id', 'position']);
        $copy->exam_id = $examId;
        $copy->bank_category_id = $categoryId;
        $copy->position = $position;
        $copy->save();
        foreach ($this->choices as $c) {
            $nc = $c->replicate();
            $nc->exam_question_id = $copy->id;
            $nc->save();
        }

        return $copy;
    }
}
