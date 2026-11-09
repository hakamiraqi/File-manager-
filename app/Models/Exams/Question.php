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
    public const CLASSIFY = 'classify';       // sort the items into categories (several items per category)
    public const UPLOAD = 'upload';           // the student uploads a file; marked by hand
    public const HOTSPOT = 'hotspot';         // click the right place on a picture
    public const DRAG_TEXT = 'drag_text';     // drag words into the blanks of the text ([[word]]), with extra wrong words
    public const DRAG_IMAGE = 'drag_image';   // drag labels onto their places on a picture
    public const AUDIO = 'audio';             // the student records a spoken answer; marked by hand
    public const DRAWING = 'drawing';         // the student draws (or writes by hand) on a canvas; marked by hand
    public const CALCULATED = 'calculated';   // numbers in the text differ per student; the answer is a formula on them
    public const DESCRIPTION = 'description'; // text or media for the questions after it, no mark
    public const RANDOM = 'random';           // a slot: N questions drawn from a bank category per student

    public const TYPES = [
        self::SINGLE, self::MULTIPLE, self::TRUE_FALSE, self::SHORT, self::NUMERIC,
        self::MATCHING, self::ORDERING, self::CLOZE, self::ESSAY, self::CLASSIFY, self::UPLOAD, self::HOTSPOT,
        self::CALCULATED, self::DRAG_TEXT, self::DRAG_IMAGE, self::AUDIO, self::DRAWING, self::DESCRIPTION, self::RANDOM,
    ];

    /** Types a person marks. */
    public const MANUAL_TYPES = [self::ESSAY, self::UPLOAD, self::AUDIO, self::DRAWING];

    /** Types whose answer is a file the page sends (never set by autosave). */
    public const FILE_TYPES = [self::UPLOAD, self::AUDIO, self::DRAWING];

    /** Types whose blanks are written in the text as [[…]]. */
    public const BLANK_TYPES = [self::CLOZE, self::DRAG_TEXT];

    /** Types whose answer is picked from the choices. */
    public const CHOICE_TYPES = [self::SINGLE, self::MULTIPLE, self::TRUE_FALSE, self::MATCHING, self::ORDERING, self::CLASSIFY, self::DRAG_IMAGE];

    protected $table = 'exam_questions';

    protected $fillable = ['exam_id', 'bank_category_id', 'type', 'format', 'tags', 'difficulty', 'body', 'feedback', 'points', 'settings', 'position', 'image'];

    protected function casts(): array
    {
        return ['body' => 'array', 'feedback' => 'array', 'settings' => 'array', 'tags' => 'array', 'difficulty' => 'integer', 'points' => 'float', 'position' => 'integer'];
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

    /** The question's text in one language, without formatting (lists, tables, results). */
    public function plainBody(?array $langs = null): string
    {
        $text = self::pick($this->body, app()->getLocale(), $langs ?? $this->exam?->languages ?? []);

        return $this->format === 'html' ? \App\Services\Exams\HtmlCleaner::text($text) : $text;
    }

    /** A random slot's pool: the category's questions, narrowed by difficulty and tag when set. */
    public static function randomPool(array $settings): \Illuminate\Support\Collection
    {
        $tag = trim((string) ($settings['tag'] ?? ''));

        return self::where('bank_category_id', $settings['category_id'] ?? 0)->whereNull('exam_id')->where('type', '!=', self::RANDOM)
            ->when(! empty($settings['difficulty']), fn ($q) => $q->where('difficulty', (int) $settings['difficulty']))
            ->get(['id', 'tags'])
            ->filter(fn (self $q) => $tag === '' || in_array($tag, $q->tags ?? [], true))
            ->pluck('id')->values();
    }

    public function isManual(): bool
    {
        return in_array($this->type, self::MANUAL_TYPES, true);
    }

    /** Whether a hand-marked answer has anything in it to mark. */
    public static function hasManualAnswer(?array $response): bool
    {
        return filled($response['text'] ?? null) || filled($response['file'] ?? null);
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
