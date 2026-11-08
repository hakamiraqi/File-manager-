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
        'score', 'max_score', 'needs_grading', 'question_order', 'drawn', 'ip', 'last_seen_at',
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
            'last_seen_at' => 'datetime', 'drawn' => 'array',
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

    /** Ties an emergency answers file to this attempt (the invigilator uploads it). */
    public function offlineToken(): string
    {
        return hash_hmac('sha256', 'offline-answers|'.$this->id.'|'.$this->user_id, (string) config('app.key'));
    }

    public function secondsLeft(): ?int
    {
        return $this->deadline_at ? max(0, (int) now()->diffInSeconds($this->deadline_at, false)) : null;
    }

    /**
     * The questions of this attempt in its order (fixed when it started): the exam's own, and
     * those drawn from the bank for its random slots, each worth the slot's points.
     */
    public function orderedQuestions(): Collection
    {
        $own = $this->exam->questions()->with('choices')->get()->where('type', '!=', Question::RANDOM)->keyBy('id');
        $drawn = collect($this->drawn ?? []);
        $fromBank = $drawn->isEmpty() ? collect() : Question::with('choices')->whereIn('id', $drawn->keys())->get()
            ->each(fn (Question $q) => $q->points = (float) $drawn[$q->id])->keyBy('id');
        $questions = $own->union($fromBank);
        $order = $this->question_order ?: $own->keys()->all();

        return collect($order)->map(fn ($id) => $questions[$id] ?? null)->filter()
            ->concat($own->except($order)->values())->values();
    }

    public function percent(): ?float
    {
        return $this->score !== null && $this->max_score > 0 ? round($this->score / $this->max_score * 100, 1) : null;
    }
}
