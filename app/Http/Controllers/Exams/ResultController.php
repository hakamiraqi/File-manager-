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
        // Marking without names: a paper number until the owner shows the names.
        $hidden = $exam->anonymous_marking && ! ($canManage && $request->boolean('names'));
        $who = fn (Attempt $a) => $hidden
            ? ['id' => null, 'name' => 'ورقة '.strtoupper(substr(hash_hmac('sha256', 'paper|'.$a->user_id.'|'.$exam->id, (string) config('app.key')), 0, 6)), 'email' => null]
            : ['id' => $a->user?->id, 'name' => $a->user?->name, 'email' => $a->user?->email];
        // Report by topic: how well each tag's questions went.
        $byTag = $graded->flatMap(fn (Question $q) => collect($q->tags ?? [])->map(fn ($t) => ['tag' => $t, 'facility' => $stats['items'][$q->id]['facility']]))
            ->filter(fn ($r) => $r['facility'] !== null)->groupBy('tag')
            ->map(fn ($rows, $tag) => ['tag' => $tag, 'questions' => $rows->count(), 'facility' => round($rows->avg('facility'))])->sortBy('facility')->values();

        return Inertia::render('Exams/Manage/Results', [
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->text('title'),
                'max_score' => $exam->maxScore(),
                'pass_mark' => $exam->pass_mark,
                'dir' => $this->presenter->dir($exam),
                'double_marking' => $exam->double_marking,
                'marking_tolerance' => $exam->marking_tolerance,
                'anonymous' => $exam->anonymous_marking,
                'names_shown' => ! $hidden,
            ],
            'by_tag' => $byTag,
            'appeals' => \App\Models\Exams\Appeal::whereIn('exam_attempt_id', $attempts->pluck('id'))->with('question')->latest('id')->get()
                ->map(fn ($ap) => [
                    'id' => $ap->id, 'attempt' => $ap->exam_attempt_id, 'student' => $who($attempts->firstWhere('id', $ap->exam_attempt_id))['name'],
                    'question' => $ap->question?->plainBody($exam->languages ?? []), 'body' => $ap->body, 'status' => $ap->status,
                    'reply' => $ap->reply, 'created_at' => $ap->created_at->toIso8601String(),
                ]),
            'can_manage' => $canManage,
            'ai_enabled' => \App\Services\Exams\AiMarker::enabled(),
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
                'user' => $who($a),
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
                        'rubric' => $ans->question->settings['rubric'] ?? [],
                        'rubric_scores' => $ans->rubric,
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
            'points' => ['required_without:rubric', 'nullable', 'numeric', 'min:0', 'max:'.$max],
            'comment' => ['nullable', 'string', 'max:5000'],
            'final' => ['sometimes', 'boolean'],
            'rubric' => ['nullable', 'array'],
        ]);
        // With a rubric, the mark is the sum of the criteria (each up to its own maximum).
        $criteria = $answer->question->settings['rubric'] ?? [];
        $rubric = null;
        if ($criteria && is_array($request->input('rubric'))) {
            $rubric = [];
            foreach ($criteria as $k => $c) {
                $v = (float) ($request->input("rubric.$k") ?? 0);
                if ($v < 0 || $v > $c['max']) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['rubric' => "«{$c['name']}» من 0 إلى {$c['max']}."]);
                }
                $rubric[$k] = $v;
            }
            // Scaled when the question is worth more or less here (drawn from the bank into a slot).
            $sum = array_sum($rubric);
            $data['points'] = round($sum * $max / max(0.001, array_sum(array_column($criteria, 'max'))), 2);
        }

        if (! $exam->double_marking || (($data['final'] ?? false) && $exam->isManageableBy($me))) {
            $answer->update(['points' => $data['points'], 'comment' => $data['comment'] ?? null, 'rubric' => $rubric]);
        } else {
            Mark::updateOrCreate(['exam_answer_id' => $answer->id, 'user_id' => $me->id], ['points' => $data['points'], 'comment' => $data['comment'] ?? null, 'rubric' => $rubric]);
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

    /**
     * Answers too alike to be chance: essays sharing many five-word runs, and multiple-choice
     * papers with the same wrong answers. For the examiner to look at, not proof of anything.
     */
    public function similarity(Request $request, Exam $exam): \Illuminate\Http\JsonResponse
    {
        abort_unless($exam->isMarkableBy($request->user()), 404);
        $attempts = $exam->attempts()->whereNotNull('submitted_at')->with(['user:id,name', 'answers.question'])->get()
            ->sortBy('number')->unique('user_id')->values();
        $pairs = [];

        // Essays: shared five-word runs (Jaccard).
        $shingles = [];
        foreach ($attempts as $a) {
            foreach ($a->answers as $ans) {
                $text = \App\Services\Exams\Grader::normalize((string) ($ans->response['text'] ?? ''));
                $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
                if ($ans->question?->type !== Question::ESSAY || count($words) < 15) {
                    continue;
                }
                $set = [];
                for ($i = 0; $i + 5 <= count($words); $i++) {
                    $set[implode(' ', array_slice($words, $i, 5))] = true;
                }
                $shingles[$ans->exam_question_id][$a->id] = $set;
            }
        }
        foreach ($shingles as $qid => $byAttempt) {
            $ids = array_keys($byAttempt);
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    $x = $byAttempt[$ids[$i]];
                    $y = $byAttempt[$ids[$j]];
                    $common = count(array_intersect_key($x, $y));
                    $score = $common / max(1, count($x) + count($y) - $common);
                    if ($score >= 0.3) {
                        $pairs[] = ['kind' => 'essay', 'question' => $qid, 'a' => $ids[$i], 'b' => $ids[$j], 'score' => round($score * 100)];
                    }
                }
            }
        }

        // Choices: the same wrong answers on the same questions.
        $wrong = [];
        foreach ($attempts as $a) {
            foreach ($a->answers as $ans) {
                if (in_array($ans->question?->type, [Question::SINGLE, Question::TRUE_FALSE], true) && $ans->points !== null && $ans->points <= 0 && isset($ans->response['choice'])) {
                    $wrong[$a->id][$ans->exam_question_id] = (int) $ans->response['choice'];
                }
            }
        }
        $ids = array_keys($wrong);
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $same = count(array_intersect_assoc($wrong[$ids[$i]], $wrong[$ids[$j]]));
                if ($same >= 3) {
                    $pairs[] = ['kind' => 'choices', 'question' => null, 'a' => $ids[$i], 'b' => $ids[$j], 'score' => $same];
                }
            }
        }

        $hidden = $exam->anonymous_marking && ! ($exam->isManageableBy($request->user()) && $request->boolean('names'));
        $name = fn ($id) => ($a = $attempts->firstWhere('id', $id)) ? ($hidden ? 'ورقة '.strtoupper(substr(hash_hmac('sha256', 'paper|'.$a->user_id.'|'.$exam->id, (string) config('app.key')), 0, 6)) : $a->user?->name) : null;

        return response()->json(collect($pairs)->sortByDesc('score')->take(100)->map(fn ($p) => $p + ['a_name' => $name($p['a']), 'b_name' => $name($p['b'])])->values());
    }

    /** A suggested mark (hosted site, with an AI key); the teacher still decides. */
    public function suggest(Request $request, Answer $answer): \Illuminate\Http\JsonResponse
    {
        abort_unless($answer->attempt->exam->isMarkableBy($request->user()) && \App\Services\Exams\AiMarker::enabled(), 404);
        try {
            return response()->json(app(\App\Services\Exams\AiMarker::class)->suggest($answer, (float) ($answer->attempt->drawn[$answer->exam_question_id] ?? $answer->question->points)));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function replyAppeal(Request $request, \App\Models\Exams\Appeal $appeal): RedirectResponse
    {
        abort_unless($appeal->attempt->exam->isMarkableBy($request->user()), 404);
        $data = $request->validate(['status' => ['required', 'in:accepted,rejected'], 'reply' => ['nullable', 'string', 'max:5000']]);
        $appeal->update($data + ['replied_by' => $request->user()->id, 'replied_at' => now()]);

        return back()->with('success', 'تم الرد على الاعتراض.');
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
