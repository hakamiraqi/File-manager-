<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\User;
use App\Services\Exams\Grader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The invigilator's view of an exam in the hall: who is present and where they sit, who has
 * started, how far each is, how long is left, what the browser reported; and what the
 * invigilator can do about it (more time, submit, reopen, a new access code, an announcement).
 */
class MonitorController extends Controller
{
    /** A student whose page has not been heard from for this long shows as disconnected. */
    public const QUIET_SECONDS = 90;

    public function __construct(private Grader $grader) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $exams = Exam::published()->with('groups:id,name')->withCount(['attempts as running' => fn ($q) => $q->whereNull('submitted_at')])
            ->when(! $user->isAdmin() && ! $user->is_invigilator, fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')->get()
            ->filter(fn (Exam $e) => $e->running > 0 || $e->windowFor($user) !== 'closed' || $e->closes_at?->gt(now()->subDay()));

        return Inertia::render('Exams/Monitor/Index', [
            'exams' => $exams->map(fn (Exam $e) => [
                'id' => $e->id, 'title' => $e->text('title'), 'subject' => $e->subject,
                'groups' => $e->groups->pluck('name'), 'running' => $e->running,
                'opens_at' => $e->opens_at?->toIso8601String(), 'closes_at' => $e->closes_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function show(Request $request, Exam $exam): Response
    {
        $this->authorizeExam($request, $exam);

        return Inertia::render('Exams/Monitor/Show', [
            'exam' => [
                'id' => $exam->id, 'title' => $exam->text('title'), 'duration' => $exam->duration_minutes,
                'access_code' => $exam->access_code, 'can_manage' => $exam->isManageableBy($request->user()),
            ],
            'data' => $this->snapshot($exam),
        ]);
    }

    /** The same rows as JSON, polled by the page every few seconds. */
    public function data(Request $request, Exam $exam): JsonResponse
    {
        $this->authorizeExam($request, $exam);

        return response()->json($this->snapshot($exam));
    }

    public function attendance(Request $request, Exam $exam, User $user): JsonResponse
    {
        $this->authorizeExam($request, $exam);
        $data = $request->validate(['present' => ['required', 'boolean'], 'seat' => ['nullable', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:255']]);
        DB::table('exam_attendance')->updateOrInsert(['exam_id' => $exam->id, 'user_id' => $user->id], $data + [
            'checked_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** More minutes for one running attempt. */
    public function extend(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeExam($request, $attempt->exam);
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:600']])['minutes'];
        abort_if($attempt->isSubmitted(), 409, 'المحاولة مُسلّمة.');
        $attempt->update(['deadline_at' => ($attempt->deadline_at && $attempt->deadline_at->isFuture() ? $attempt->deadline_at : now())->copy()->addMinutes($minutes)]);
        $this->log($request, $attempt, "أعطى {$minutes} دقيقة إضافية");

        return response()->json(['ok' => true]);
    }

    /** Submits an attempt now (a student who left, or the end of the session). */
    public function submit(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeExam($request, $attempt->exam);
        DB::transaction(function () use ($attempt) {
            $locked = Attempt::whereKey($attempt->id)->lockForUpdate()->first();
            if (! $locked->isSubmitted()) {
                $locked->update(['submitted_at' => now()]);
                $this->grader->grade($locked);
            }
        });
        $this->log($request, $attempt, 'سلّم المحاولة');

        return response()->json(['ok' => true]);
    }

    /** Opens a submitted attempt again (a student submitted by mistake, a computer failed). */
    public function reopen(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeExam($request, $attempt->exam);
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:600']])['minutes'];
        $attempt->update(['submitted_at' => null, 'score' => null, 'needs_grading' => false, 'deadline_at' => now()->addMinutes($minutes)]);
        $this->log($request, $attempt, "أعاد فتح المحاولة {$minutes} دقيقة");

        return response()->json(['ok' => true]);
    }

    public function submitAll(Request $request, Exam $exam): JsonResponse
    {
        $this->authorizeExam($request, $exam);
        $exam->attempts()->whereNull('submitted_at')->get()->each(function (Attempt $a) {
            $a->update(['submitted_at' => now()]);
            $this->grader->grade($a);
        });
        ActivityLog::record('exam.monitor', "سلّم كل المحاولات الجارية في \"{$exam->text('title')}\"", $exam);

        return response()->json(['ok' => true]);
    }

    public function accessCode(Request $request, Exam $exam): JsonResponse
    {
        $this->authorizeExam($request, $exam);
        $code = $request->boolean('clear') ? null : collect(str_split(str_repeat('23456789ABCDEFGHJKLMNPQRSTUVWXYZ', 2)))->shuffle()->take(6)->implode('');
        $exam->update(['access_code' => $code]);

        return response()->json(['access_code' => $code]);
    }

    public function announce(Request $request, Exam $exam): JsonResponse
    {
        $this->authorizeExam($request, $exam);
        $body = $request->validate(['body' => ['required', 'string', 'max:500']])['body'];
        DB::table('exam_announcements')->insert(['exam_id' => $exam->id, 'user_id' => $request->user()->id, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** The attendance sheet to print and sign. */
    public function sheet(Request $request, Exam $exam): Response
    {
        $this->authorizeExam($request, $exam);
        $snapshot = $this->snapshot($exam);

        return Inertia::render('Exams/Monitor/Sheet', [
            'exam' => ['id' => $exam->id, 'title' => $exam->text('title'), 'subject' => $exam->subject, 'stage' => $exam->stage,
                'groups' => $exam->groups()->pluck('name'), 'date' => now()->toIso8601String()],
            'site' => config('app.name'),
            'rows' => $snapshot['rows'],
        ]);
    }

    private function snapshot(Exam $exam): array
    {
        $roster = $exam->roster();
        $attempts = $exam->attempts()->withCount(['answers as answered' => fn ($q) => $q->whereNotNull('response')])
            ->with('events:id,exam_attempt_id,type')->orderBy('number')->get()->groupBy('user_id');
        $attendance = DB::table('exam_attendance')->where('exam_id', $exam->id)->get()->keyBy('user_id');
        $questionCount = $exam->questions()->where('type', '!=', 'description')->get()
            ->sum(fn ($q) => $q->type === 'random' ? (int) ($q->settings['count'] ?? 0) : 1);

        $rows = $roster->map(function (User $u) use ($attempts, $attendance, $questionCount) {
            $a = $attempts->get($u->id)?->last();
            $seen = $a?->last_seen_at ?? $a?->started_at;
            $status = match (true) {
                ! $a => isset($attendance[$u->id]) && ! $attendance[$u->id]->present ? 'absent' : 'not_started',
                $a->isSubmitted() => 'submitted',
                $a->isExpired(\App\Models\Exams\Attempt::GRACE_SECONDS) => 'time_up',
                $seen && $seen->lt(now()->subSeconds(self::QUIET_SECONDS)) => 'disconnected',
                default => 'working',
            };

            return [
                'user' => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'extra_time' => (float) $u->exam_time_multiplier > 1],
                'present' => isset($attendance[$u->id]) ? (bool) $attendance[$u->id]->present : null,
                'seat' => $attendance[$u->id]->seat ?? null,
                'note' => $attendance[$u->id]->note ?? null,
                'status' => $status,
                'attempt' => $a ? [
                    'id' => $a->id, 'number' => $a->number, 'answered' => $a->answered, 'questions' => $questionCount,
                    'seconds_left' => $a->isSubmitted() ? null : $a->secondsLeft(),
                    'started_at' => $a->started_at->toIso8601String(), 'submitted_at' => $a->submitted_at?->toIso8601String(),
                    'last_seen' => $seen?->toIso8601String(), 'ip' => $a->ip, 'events' => $a->events->countBy('type'),
                ] : null,
            ];
        })->values();

        return [
            'rows' => $rows,
            'counts' => $rows->countBy('status')->all() + ['total' => $rows->count(), 'present' => $rows->where('present', true)->count()],
            'announcements' => DB::table('exam_announcements')->where('exam_id', $exam->id)->latest('id')->limit(10)->get(['body', 'created_at']),
            'now' => now()->toIso8601String(),
        ];
    }

    private function log(Request $request, Attempt $attempt, string $what): void
    {
        ActivityLog::record('exam.monitor', "{$what} للطالب {$attempt->user?->name} في \"{$attempt->exam->text('title')}\"", $attempt->exam);
    }

    private function authorizeExam(Request $request, Exam $exam): void
    {
        abort_unless($exam->isMonitorableBy($request->user()), 404);
    }
}
