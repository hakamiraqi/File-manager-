<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Answer;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Mark;
use App\Models\Exams\Override;
use App\Models\Exams\Question;
use App\Models\User;
use App\Services\Exams\Grader;
use App\Services\Exams\Presenter;
use App\Services\Exams\Statistics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The examiner's results page: attempts, marking essays, per-student overrides and the export. */
class ResultController extends Controller
{
    public function __construct(private Presenter $presenter, private Grader $grader) {}

    public function index(Request $request, Exam $exam): Response
    {
        $me = $request->user();
        abort_unless($exam->isMarkableBy($me), 404);
        $canManage = $exam->isManageableBy($me);
        $exam->load('questions.choices');
        $attempts = $exam->attempts()->with(['user:id,name,email', 'answers.question', 'answers.marks.user:id,name', 'events'])->orderBy('user_id')->orderBy('number')->get();
        $graded = $exam->questions->filter(fn (Question $q) => $q->isGraded());
        $submitted = $attempts->whereNotNull('submitted_at')->whereNotNull('score');
        $stats = (new Statistics)->analyse($graded->values(), $attempts);

        return Inertia::render('Exams/Manage/Results', [
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->text('title'),
                'max_score' => $exam->maxScore(),
                'pass_mark' => $exam->pass_mark,
                'dir' => $this->presenter->dir($exam),
                'double_marking' => $exam->double_marking,
                'marking_tolerance' => $exam->marking_tolerance,
            ],
            'can_manage' => $canManage,
            'markers' => $exam->markers()->get(['users.id', 'name', 'email'])->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]),
            'summary' => [
                'attempts' => $attempts->count(),
                'students' => $attempts->pluck('user_id')->unique()->count(),
                'submitted' => $attempts->whereNotNull('submitted_at')->count(),
                'pending' => $attempts->where('needs_grading', true)->count(),
                'average' => $submitted->isEmpty() ? null : round($submitted->avg(fn ($a) => $a->percent()), 1),
            ],
            'stats' => collect($stats)->except('items'),
            'questions' => $graded->values()->map(function (Question $q, $i) use ($stats) {
                return [
                    'id' => $q->id,
                    'number' => $i + 1,
                    'type' => $q->type,
                    'body' => $q->plainBody($exam->languages ?? []),
                    'points' => $q->points,
                    'facility' => $stats['items'][$q->id]['facility'],
                    'discrimination' => $stats['items'][$q->id]['discrimination'],
                ];
            }),
            'attempts' => $attempts->map(fn (Attempt $a) => [
                'id' => $a->id,
                'user' => ['id' => $a->user?->id, 'name' => $a->user?->name, 'email' => $a->user?->email],
                'number' => $a->number,
                'started_at' => $a->started_at->toIso8601String(),
                'submitted_at' => $a->submitted_at?->toIso8601String(),
                'score' => $a->score,
                'max_score' => $a->max_score,
                'percent' => $a->percent(),
                'needs_grading' => $a->needs_grading,
                'events' => $a->events->countBy('type'),
                'essays' => $a->answers->filter(fn (Answer $ans) => $ans->question?->isManual())
                    ->map(fn (Answer $ans) => [
                        'id' => $ans->id,
                        'question' => $ans->question->plainBody($exam->languages ?? []),
                        'max' => $a->drawn[$ans->exam_question_id] ?? $ans->question->points,
                        'text' => $ans->response['text'] ?? '',
                        'file' => isset($ans->response['file']) ? ['name' => $ans->response['name'] ?? '', 'url' => route('exams.answers.file', $ans)] : null,
                        'points' => $ans->points,
                        'comment' => $ans->comment,
                    ] + $this->marking($exam, $ans, $me, $canManage))->values(),
            ]),
            'overrides' => $exam->overrides()->with('user:id,name,email')->get()->map(fn (Override $o) => [
                'id' => $o->id,
                'user' => ['id' => $o->user?->id, 'name' => $o->user?->name, 'email' => $o->user?->email],
                'extra_minutes' => $o->extra_minutes,
                'closes_at' => $o->closes_at?->format('Y-m-d\TH:i'),
                'max_attempts' => $o->max_attempts,
                'reason' => $o->reason,
            ]),
        ]);
    }

    /**
     * Marks one hand-marked answer. Single marking sets its points. With double marking each
     * marker gives a mark; two marks within the exam's tolerance give their average, two far
     * apart wait for the owner, whose mark with «final» settles the answer.
     */
    public function grade(Request $request, Answer $answer): RedirectResponse
    {
        $attempt = $answer->attempt;
        $exam = $attempt->exam;
        $me = $request->user();
        abort_unless($exam->isMarkableBy($me), 404);
        $max = $attempt->drawn[$answer->exam_question_id] ?? $answer->question->points;
        $data = $request->validate([
            'points' => ['required', 'numeric', 'min:0', 'max:'.$max],
            'comment' => ['nullable', 'string', 'max:5000'],
            'final' => ['sometimes', 'boolean'],
        ]);

        if (! $exam->double_marking || (($data['final'] ?? false) && $exam->isManageableBy($me))) {
            $answer->update(['points' => $data['points'], 'comment' => $data['comment'] ?? null]);
        } else {
            Mark::updateOrCreate(['exam_answer_id' => $answer->id, 'user_id' => $me->id], ['points' => $data['points'], 'comment' => $data['comment'] ?? null]);
            $this->settle($exam, $answer, $max);
        }
        $this->grader->retotal($attempt);
        ActivityLog::record('exam.graded', "صحّح إجابة في الامتحان \"{$exam->text('title')}\"", $exam);

        return back()->with('success', 'تم حفظ الدرجة.');
    }

    /** Double marking: the answer's points from its marks, or none while they disagree. */
    private function settle(Exam $exam, Answer $answer, float $max): void
    {
        $marks = $answer->marks()->get();
        if ($marks->count() < 2) {
            $answer->update(['points' => null]);

            return;
        }
        $spread = $marks->max('points') - $marks->min('points');
        $agreed = $spread <= $max * $exam->marking_tolerance / 100 + 1e-9;
        $answer->update([
            'points' => $agreed ? round($marks->avg('points'), 2) : null,
            'comment' => $agreed ? ($marks->pluck('comment')->filter()->implode("\n") ?: null) : $answer->comment,
        ]);
    }

    /** What the marking page shows of an answer's marks; others' marks stay hidden until you mark (blind marking). */
    private function marking(Exam $exam, Answer $answer, User $me, bool $canManage): array
    {
        if (! $exam->double_marking) {
            return ['marks' => [], 'status' => null, 'my_mark' => null];
        }
        $marks = $answer->marks;
        $mine = $marks->firstWhere('user_id', $me->id);
        $max = $answer->question->points;
        $status = match (true) {
            $marks->count() === 0 => 'unmarked',
            $marks->count() === 1 => 'second_needed',
            $answer->points === null => 'disputed',
            $marks->max('points') - $marks->min('points') > $max * $exam->marking_tolerance / 100 + 1e-9 => 'settled',
            default => 'agreed',
        };

        return [
            'status' => $status,
            'my_mark' => $mine ? ['points' => $mine->points, 'comment' => $mine->comment] : null,
            'marks' => $mine || $canManage ? $marks->map(fn (Mark $m) => [
                'name' => $m->user?->name, 'points' => $m->points, 'comment' => $m->comment, 'mine' => $m->user_id === $me->id,
            ])->values() : [],
        ];
    }

    public function addMarker(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizeExam($request, $exam);
        $data = $request->validate(['email' => ['required', 'email', 'exists:users,email']], ['email.exists' => 'لا يوجد مستخدم بهذا البريد.']);
        $user = User::where('email', $data['email'])->firstOrFail();
        if (! $user->canManageExams()) {
            return back()->withErrors(['email' => 'المصحّح يجب أن يكون حساب أستاذ.']);
        }
        $exam->markers()->syncWithoutDetaching([$user->id]);

        return back()->with('success', 'أُضيف المصحّح.');
    }

    public function removeMarker(Request $request, Exam $exam, User $user): RedirectResponse
    {
        $this->authorizeExam($request, $exam);
        $exam->markers()->detach($user->id);

        return back()->with('success', 'أُزيل المصحّح.');
    }

    /** Re-marks every submitted attempt (after fixing a wrong key). Hand marks are kept. */
    public function regrade(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizeExam($request, $exam);
        $exam->attempts()->whereNotNull('submitted_at')->get()->each(fn (Attempt $a) => $this->grader->grade($a));

        return back()->with('success', 'أعيد تصحيح كل المحاولات.');
    }

    public function override(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizeExam($request, $exam);
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'extra_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'closes_at' => ['nullable', 'date'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:100'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], ['email.exists' => 'لا يوجد مستخدم بهذا البريد.']);
        $user = User::where('email', $data['email'])->firstOrFail();
        Override::updateOrCreate(['exam_id' => $exam->id, 'user_id' => $user->id], collect($data)->except('email')->all());

        // Extra minutes reach an attempt already running.
        if (filled($data['extra_minutes'] ?? null)) {
            $exam->attempts()->where('user_id', $user->id)->whereNull('submitted_at')->whereNotNull('deadline_at')->get()
                ->each(function (Attempt $a) use ($exam, $user) {
                    $deadline = $a->started_at->copy()->addMinutes($exam->fresh()->minutesFor($user));
                    $closes = $exam->fresh()->closesAtFor($user);
                    $a->update(['deadline_at' => $closes && $closes->lt($deadline) ? $closes : $deadline]);
                });
        }

        return back()->with('success', 'تم حفظ الاستثناء.');
    }

    public function removeOverride(Request $request, Override $override): RedirectResponse
    {
        $this->authorizeExam($request, $override->exam);
        $override->delete();

        return back()->with('success', 'تم حذف الاستثناء.');
    }

    public function export(Request $request, Exam $exam): StreamedResponse
    {
        $this->authorizeExam($request, $exam);
        $attempts = $exam->attempts()->with(['user:id,name,email', 'events'])->orderBy('user_id')->orderBy('number')->get();

        return response()->streamDownload(function () use ($attempts) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['الطالب', 'البريد', 'المحاولة', 'البداية', 'التسليم', 'الدرجة', 'من', 'النسبة %', 'بانتظار التصحيح', 'مغادرة الصفحة']);
            foreach ($attempts as $a) {
                fputcsv($out, [
                    $a->user?->name, $a->user?->email, $a->number, $a->started_at->format('Y-m-d H:i'),
                    $a->submitted_at?->format('Y-m-d H:i'), $a->score, $a->max_score, $a->percent(),
                    $a->needs_grading ? 'نعم' : '', $a->events->whereIn('type', ['hidden', 'blur'])->count(),
                ]);
            }
            fclose($out);
        }, 'exam-'.$exam->id.'-results-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeExam(Request $request, Exam $exam): void
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
    }
}
