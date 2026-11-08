<?php

namespace App\Models\Exams;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Attempt extends Model
{
    /** Seconds after the deadline in which answers sent as the timer ran out are still taken. */
    public const GRACE_SECONDS = 60;

    protected $table = 'exam_attempts';

    protected $fillable = [
        'exam_id', 'user_id', 'number', 'started_at', 'deadline_at', 'submitted_at',
        'score', 'max_score', 'needs_grading', 'question_order', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score' => 'float',
            'max_score' => 'float',
            'needs_grading' => 'boolean',
            'question_order' => 'array',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'exam_attempt_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'exam_attempt_id');
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function isExpired(int $grace = 0): bool
    {
        return $this->deadline_at !== null && now()->gt($this->deadline_at->copy()->addSeconds($grace));
    }

    public function secondsLeft(): ?int
    {
        return $this->deadline_at ? max(0, (int) now()->diffInSeconds($this->deadline_at, false)) : null;
    }

    /** The exam's questions in this attempt's order (fixed when the attempt started). */
    public function orderedQuestions(): Collection
    {
        $questions = $this->exam->questions()->with('choices')->get()->keyBy('id');
        $order = $this->question_order ?: $questions->keys()->all();

        return collect($order)->map(fn ($id) => $questions[$id] ?? null)->filter()
            ->concat($questions->except($order)->values())->values();
    }

    public function percent(): ?float
    {
        return $this->score !== null && $this->max_score > 0 ? round($this->score / $this->max_score * 100, 1) : null;
    }
}
