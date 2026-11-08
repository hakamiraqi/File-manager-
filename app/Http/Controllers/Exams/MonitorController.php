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
                'resume_code_required' => $exam->resume_code_required, 'bind_computers' => $exam->bind_computers,
            ],
            'computers' => \App\Models\Exams\Computer::orderBy('room')->orderBy('label')->get(['id', 'room', 'label', 'ip']),
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

    /**
     * An emergency answers file a student's page saved when the network did not come back:
     * its answers go into the attempt (marked in its alerts), and it is submitted if asked.
     */
    public function offlineFile(Request $request, Exam $exam): JsonResponse
    {
        $this->authorizeExam($request, $exam);
        $request->validate(['file' => ['required', 'file', 'max:5120'], 'submit' => ['sometimes', 'boolean']]);
        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        $attempt = is_array($data) && ($data['kind'] ?? null) === 'examnest-offline-answers'
            ? Attempt::where('exam_id', $exam->id)->find((int) ($data['attempt'] ?? 0)) : null;
        if (! $attempt || ! hash_equals($attempt->offlineToken(), (string) ($data['token'] ?? ''))) {
            return response()->json(['message' => 'الملف ليس ملف إجابات لهذا الامتحان، أو عُدّل.'], 422);
        }
        if ($attempt->isSubmitted() && $attempt->score !== null && ! $request->boolean('force')) {
            return response()->json(['message' => 'المحاولة مُسلّمة. أعد فتحها أولاً إن كان الملف أحدث.'], 409);
        }

        $ids = $attempt->orderedQuestions()->reject(fn ($q) => in_array($q->type, \App\Models\Exams\Question::FILE_TYPES, true))->pluck('id')->all();
        $count = 0;
        foreach ((array) ($data['answers'] ?? []) as $qid => $answer) {
            if (! in_array((int) $qid, $ids, true) || ! is_array($answer['response'] ?? null)) {
                continue;
            }
            \App\Models\Exams\Answer::updateOrCreate(['exam_attempt_id' => $attempt->id, 'exam_question_id' => (int) $qid],
                ['response' => $answer['response'], 'flagged' => (bool) ($answer['flagged'] ?? false)]);
            $count++;
        }
        \App\Models\Exams\Event::create(['exam_attempt_id' => $attempt->id, 'type' => 'offline_file',
            'meta' => ['answers' => $count, 'saved_at' => $data['saved_at'] ?? null, 'by' => $request->user()->id]]);
        if ($request->boolean('submit') && ! $attempt->isSubmitted()) {
            $attempt->update(['submitted_at' => now()]);
            $this->grader->grade($attempt);
        }
        $this->log($request, $attempt, "رفع ملف إجابات طارئاً ({$count} إجابة)");

        return response()->json(['ok' => true, 'student' => $attempt->user?->name, 'answers' => $count]);
    }

    public function unlock(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeExam($request, $attempt->exam);
        $attempt->unlock();
        $this->log($request, $attempt, 'فتح المحاولة المقفلة');

        return response()->json(['ok' => true]);
    }

    public function helpDone(Request $request, Attempt $attempt): JsonResponse
    {
        $this->authorizeExam($request, $attempt->exam);
        $attempt->update(['help_requested_at' => null]);

        return response()->json(['ok' => true]);
    }

    /** The hall switches, during the exam: the resume code and binding students to computers. */
    public function settings(Request $request, Exam $exam): JsonResponse
    {
        $this->authorizeExam($request, $exam);
        $data = $request->validate(['resume_code_required' => ['sometimes', 'boolean'], 'bind_computers' => ['sometimes', 'boolean']]);
        $exam->update($data);
        if (array_key_exists('resume_code_required', $data) && ! $data['resume_code_required']) {
            $exam->attempts()->whereNotNull('locked_at')->get()->each->unlock();
        }

        return response()->json($exam->only('resume_code_required', 'bind_computers'));
    }

    /** Puts one student at a computer (or nowhere). */
    public function seat(Request $request, Exam $exam, User $user): JsonResponse
    {
        $this->authorizeExam($request, $exam);
        $id = $request->validate(['computer_id' => ['nullable', 'integer', 'exists:exam_computers,id']])['computer_id'] ?? null;
        if ($id) {
            DB::table('exam_attendance')->where('exam_id', $exam->id)->where('computer_id', $id)->where('user_id', '!=', $user->id)->update(['computer_id' => null]);
        }
        $pc = $id ? \App\Models\Exams\Computer::find($id) : null;
        DB::table('exam_attendance')->updateOrInsert(['exam_id' => $exam->id, 'user_id' => $user->id],
            ['computer_id' => $id, 'seat' => $pc?->label, 'checked_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Spreads the students over a room's computers, by name or at random; absent students are left out. */
    public function distribute(Request $request, Exam $exam): JsonResponse
    {
        $this->authorizeExam($request, $exam);
        $data = $request->validate(['room' => ['nullable', 'string', 'max:100'], 'order' => ['required', 'in:name,random']]);
        $computers = \App\Models\Exams\Computer::when(filled($data['room'] ?? null), fn ($q) => $q->where('room', $data['room']))->get()
            ->sortBy('label', SORT_NATURAL)->values();
        $absent = DB::table('exam_attendance')->where('exam_id', $exam->id)->where('present', false)->pluck('user_id')->all();
        $students = $exam->roster()->reject(fn (User $u) => in_array($u->id, $absent, true))->values();
        if ($data['order'] === 'random') {
            $students = $students->shuffle()->values();
        }
        DB::transaction(function () use ($exam, $students, $computers, $request) {
            DB::table('exam_attendance')->where('exam_id', $exam->id)->update(['computer_id' => null, 'seat' => null]);
            foreach ($students as $i => $u) {
                $pc = $computers[$i] ?? null;
                if (! $pc) {
                    break;
                }
                DB::table('exam_attendance')->updateOrInsert(['exam_id' => $exam->id, 'user_id' => $u->id],
                    ['computer_id' => $pc->id, 'seat' => $pc->label, 'checked_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);
            }
        });

        return response()->json(['seated' => min($students->count(), $computers->count()), 'students' => $students->count(), 'computers' => $computers->count()]);
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
        $computers = \App\Models\Exams\Computer::whereIn('id', $attendance->pluck('computer_id')->filter())->get()->keyBy('id');
        $questionCount = $exam->questions()->where('type', '!=', 'description')->get()
            ->sum(fn ($q) => $q->type === 'random' ? (int) ($q->settings['count'] ?? 0) : 1);

        $rows = $roster->map(function (User $u) use ($attempts, $attendance, $questionCount, $computers) {
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
                'computer' => ($pc = $computers[$attendance[$u->id]->computer_id ?? 0] ?? null) ? ['id' => $pc->id, 'label' => $pc->label, 'ip' => $pc->ip] : null,
                'status' => $status,
                'attempt' => $a ? [
                    'id' => $a->id, 'number' => $a->number, 'answered' => $a->answered, 'questions' => $questionCount,
                    'seconds_left' => $a->isSubmitted() ? null : $a->secondsLeft(),
                    'started_at' => $a->started_at->toIso8601String(), 'submitted_at' => $a->submitted_at?->toIso8601String(),
                    'last_seen' => $seen?->toIso8601String(), 'ip' => $a->ip, 'events' => $a->events->countBy('type'),
                    'locked' => $a->locked_at ? ['reason' => $a->lock_reason, 'code' => $a->resume_code] : null,
                    'help' => $a->help_requested_at?->toIso8601String(),
                ] : null,
            ];
        })->values();

        return [
            'rows' => $rows,
            'counts' => $rows->countBy('status')->all() + ['total' => $rows->count(), 'present' => $rows->where('present', true)->count(),
                'help' => $rows->filter(fn ($r) => $r['attempt']['help'] ?? null)->count(), 'locked' => $rows->filter(fn ($r) => $r['attempt']['locked'] ?? null)->count()],
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
