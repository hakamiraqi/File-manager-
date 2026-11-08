<?php

namespace App\Models\Exams;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use Translatable;

    public const LANGUAGES = ['ar', 'en', 'ku'];

    public const GRADING = ['highest', 'average', 'first', 'last'];

    public const SHOW_RESULTS = ['after_submit', 'after_close', 'at_date', 'never'];

    protected $fillable = [
        'user_id', 'title', 'description', 'subject', 'stage', 'department', 'languages', 'direction',
        'duration_minutes', 'opens_at', 'closes_at', 'max_attempts', 'grading_method', 'pass_mark', 'password',
        'shuffle_questions', 'shuffle_choices', 'show_results', 'show_answers', 'is_published',
        'is_practice', 'seb_required', 'seb_keys', 'double_marking', 'marking_tolerance',
        'access_code', 'allowed_ips', 'resume_code_required', 'bind_computers', 'navigation', 'calculator',
        'results_at', 'anonymous_marking', 'appeals_open',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'description' => 'array',
            'languages' => 'array',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'duration_minutes' => 'integer',
            'max_attempts' => 'integer',
            'pass_mark' => 'float',
            'shuffle_questions' => 'boolean',
            'shuffle_choices' => 'boolean',
            'show_answers' => 'boolean',
            'is_published' => 'boolean',
            'is_practice' => 'boolean',
            'seb_required' => 'boolean',
            'double_marking' => 'boolean',
            'resume_code_required' => 'boolean',
            'results_at' => 'datetime',
            'anonymous_marking' => 'boolean',
            'appeals_open' => 'boolean',
            'bind_computers' => 'boolean',
            'marking_tolerance' => 'integer',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position')->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(Override::class);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_published', true);
    }

    public function scopeManageableBy(Builder $q, User $user): Builder
    {
        return $user->isAdmin() ? $q : $q->where('user_id', $user->id);
    }

    /** The classes this exam is given to; none means every student. */
    public function groups(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'exam_exam_group', 'exam_id', 'exam_group_id');
    }

    /** Published exams this student may see: given to everyone, or to a group they are in. */
    public function scopeAssignedTo(Builder $q, User $user): Builder
    {
        return $q->where(fn ($w) => $w->whereDoesntHave('groups')
            ->orWhereHas('groups.members', fn ($m) => $m->whereKey($user->id)));
    }

    public function isAssignedTo(User $user): bool
    {
        return ! $this->groups()->exists() || $this->groups()->whereHas('members', fn ($m) => $m->whereKey($user->id))->exists();
    }

    /** Whether the request comes from the hall's network, when the exam is kept to it. */
    public function networkAllows(\Illuminate\Http\Request $request): bool
    {
        $ranges = preg_split('/[\s,،]+/u', trim((string) $this->allowed_ips), -1, PREG_SPLIT_NO_EMPTY);

        return $ranges === [] || \Symfony\Component\HttpFoundation\IpUtils::checkIp((string) $request->ip(), $ranges);
    }

    /** The computer this student was given in the hall for this exam, if any. */
    public function computerFor(User $user): ?Computer
    {
        $id = \Illuminate\Support\Facades\DB::table('exam_attendance')->where('exam_id', $this->id)->where('user_id', $user->id)->value('computer_id');

        return $id ? Computer::find($id) : null;
    }

    /** With computers bound, a student sits only at the computer they were given. */
    public function computerAllows(User $user, \Illuminate\Http\Request $request): ?string
    {
        if (! $this->bind_computers || ! ($pc = $this->computerFor($user))) {
            return null;
        }

        return $request->ip() === $pc->ip ? null : "هذه ليست حاسبتك. حاسبتك في هذا الامتحان: {$pc->label}".($pc->room ? " ({$pc->room})" : '').'.';
    }

    /** Who may watch the exam in the hall: its owner, admins, and invigilators. */
    public function isMonitorableBy(User $user): bool
    {
        return $this->isManageableBy($user) || (bool) $user->is_invigilator;
    }

    /**
     * The students expected to sit it: its groups' members, or (given to everyone) those who
     * started it or were marked present.
     */
    public function roster(): \Illuminate\Support\Collection
    {
        if ($this->groups()->exists()) {
            return User::whereHas('examGroups', fn ($g) => $g->whereIn('exam_groups.id', $this->groups()->pluck('exam_groups.id')))
                ->orderBy('name')->get(['id', 'name', 'email', 'exam_time_multiplier']);
        }
        $ids = $this->attempts()->pluck('user_id')->merge(\Illuminate\Support\Facades\DB::table('exam_attendance')->where('exam_id', $this->id)->pluck('user_id'));

        return User::whereIn('id', $ids->unique())->orderBy('name')->get(['id', 'name', 'email', 'exam_time_multiplier']);
    }

    /** Teachers invited to mark this exam's hand-marked answers (beside its owner). */
    public function markers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'exam_markers')->withTimestamps();
    }

    /** The owner and admins, and invited markers: they see the results and mark answers. */
    public function isMarkableBy(User $user): bool
    {
        return $this->isManageableBy($user) || ($user->canManageExams() && $this->markers()->whereKey($user->id)->exists());
    }

    public function isManageableBy(User $user): bool
    {
        return $user->canManageExams() && ($user->isAdmin() || $this->user_id === $user->id);
    }

    public function text(string $field, ?string $locale = null): string
    {
        return self::pick($this->{$field}, $locale ?? app()->getLocale(), $this->languages ?? []);
    }

    /**
     * Whether this request may sit the exam: anything when Safe Exam Browser is not required;
     * otherwise SEB itself and, when the examiner gave its keys, the right configuration.
     * SEB sends SHA-256(page URL + key) in X-SafeExamBrowser-ConfigKeyHash (Config Key)
     * and X-SafeExamBrowser-RequestHash (Browser Exam Key).
     */
    public function sebAllows(\Illuminate\Http\Request $request): bool
    {
        if (! $this->seb_required) {
            return true;
        }
        if (! str_contains((string) $request->userAgent(), 'SEB')) {
            return false;
        }
        $keys = preg_split('/\s+/', trim((string) $this->seb_keys), -1, PREG_SPLIT_NO_EMPTY);
        if ($keys === []) {
            return true;
        }
        $hashes = array_map('strtolower', array_filter([
            $request->header('X-SafeExamBrowser-ConfigKeyHash'), $request->header('X-SafeExamBrowser-RequestHash'),
        ]));
        // Behind the proxy the request may look like http; SEB hashed the address it opened.
        $urls = array_unique([$request->fullUrl(), rtrim((string) config('app.url'), '/').$request->getRequestUri()]);
        foreach ($keys as $key) {
            foreach ($urls as $url) {
                if (in_array(hash('sha256', $url.$key), $hashes, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function overrideFor(User $user): ?Override
    {
        return $this->overrides->firstWhere('user_id', $user->id)
            ?? $this->overrides()->where('user_id', $user->id)->first();
    }

    /** The closing time for this student (an override may extend it). */
    public function closesAtFor(User $user): ?\Illuminate\Support\Carbon
    {
        return $this->overrideFor($user)?->closes_at ?? $this->closes_at;
    }

    public function maxAttemptsFor(User $user): int
    {
        return $this->overrideFor($user)?->max_attempts ?? $this->max_attempts;
    }

    /** Minutes this student gets for one attempt (null = no limit). */
    public function minutesFor(User $user): ?int
    {
        if (! $this->duration_minutes) {
            return null;
        }
        $minutes = $this->duration_minutes * max(1, (float) ($user->exam_time_multiplier ?: 1));

        return (int) ceil($minutes) + (int) ($this->overrideFor($user)?->extra_minutes ?? 0);
    }

    /** 'upcoming' | 'open' | 'closed' for this student. */
    public function windowFor(User $user): string
    {
        if ($this->opens_at && now()->lt($this->opens_at)) {
            return 'upcoming';
        }
        $closes = $this->closesAtFor($user);

        return $closes && now()->gte($closes) ? 'closed' : 'open';
    }

    public function maxScore(): float
    {
        return (float) $this->questions->where('type', '!=', Question::DESCRIPTION)->sum(fn (Question $q) => $q->slotPoints());
    }

    /** May this student see their mark now? */
    public function resultsVisibleTo(User $user): bool
    {
        return match ($this->show_results) {
            'after_submit' => true,
            'after_close' => $this->windowFor($user) === 'closed',
            'at_date' => $this->results_at !== null && now()->gte($this->results_at),
            default => false,
        };
    }

    /** The mark that counts for a student, by the exam's grading method (null = nothing marked yet). */
    public function finalScoreFor(User $user): ?float
    {
        $scores = $this->attempts()->where('user_id', $user->id)->whereNotNull('submitted_at')
            ->orderBy('number')->pluck('score')->filter(fn ($s) => $s !== null)->values();
        if ($scores->isEmpty()) {
            return null;
        }

        return (float) match ($this->grading_method) {
            'average' => round($scores->avg(), 2),
            'first' => $scores->first(),
            'last' => $scores->last(),
            default => $scores->max(),
        };
    }
}
