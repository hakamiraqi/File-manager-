<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Answer;
use App\Models\Exams\Attempt;
use App\Models\Exams\Event;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Services\Exams\Grader;
use App\Services\Exams\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The student's side: the list of exams, sitting one, and the result. */
class ExamController extends Controller
{
    public function __construct(private Presenter $presenter, private Grader $grader) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $exams = Exam::published()->withCount('questions')
            ->with(['attempts' => fn ($q) => $q->where('user_id', $user->id), 'overrides' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderByRaw('closes_at is null, closes_at')->latest('id')->get();

        // Close attempts whose time ran out while the student was away.
        $exams->each(fn (Exam $e) => $e->attempts->each(fn (Attempt $a) => $this->closeIfExpired($a)));

        return Inertia::render('Exams/Index', [
            'exams' => $exams->map(fn (Exam $e) => $this->presenter->card($e, $user))->values(),
            'canManage' => $user->canManageExams(),
        ]);
    }

    public function show(Request $request, Exam $exam): Response
    {
        $user = $request->user();
        abort_unless($exam->is_published || $exam->isManageableBy($user), 404);
        $exam->load(['attempts' => fn ($q) => $q->where('user_id', $user->id)->orderBy('number'), 'overrides' => fn ($q) => $q->where('user_id', $user->id)])
            ->loadCount('questions');
        $exam->attempts->each(fn (Attempt $a) => $this->closeIfExpired($a));
        $visible = $exam->resultsVisibleTo($user);

        return Inertia::render('Exams/Show', [
            'exam' => $this->presenter->card($exam, $user) + [
                'description' => $exam->text('description'),
                'needs_password' => filled($exam->password),
                'grading_method' => $exam->grading_method,
                'pass_mark' => $exam->pass_mark,
            ],
            'attempts' => $exam->attempts->map(fn (Attempt $a) => [
                'id' => $a->id,
                'number' => $a->number,
                'started_at' => $a->started_at->toIso8601String(),
                'submitted_at' => $a->submitted_at?->toIso8601String(),
                'score' => $visible ? $a->score : null,
                'max_score' => $a->max_score,
                'needs_grading' => $a->needs_grading,
            ])->values(),
        ]);
    }

    public function start(Request $request, Exam $exam): RedirectResponse
    {
        $user = $request->user();
        abort_unless($exam->is_published, 404);
        $exam->load(['overrides' => fn ($q) => $q->where('user_id', $user->id)]);

        $open = $exam->attempts()->where('user_id', $user->id)->whereNull('submitted_at')->latest('id')->first();
        if ($open && ! $this->closeIfExpired($open)) {
            return redirect()->route('exams.attempts.take', $open);
        }
        if ($exam->windowFor($user) !== 'open') {
            return back()->withErrors(['exam' => $exam->windowFor($user) === 'upcoming' ? 'لم يبدأ الامتحان بعد.' : 'انتهى وقت الامتحان.']);
        }
        $used = $exam->attempts()->where('user_id', $user->id)->count();
        if ($used >= $exam->maxAttemptsFor($user)) {
            return back()->withErrors(['exam' => 'استنفدت عدد المحاولات المسموح.']);
        }
        if (filled($exam->password) && ! hash_equals((string) $exam->password, (string) $request->input('password'))) {
            return back()->withErrors(['password' => 'كلمة مرور الامتحان غير صحيحة.']);
        }
        if (! $exam->questions()->exists()) {
            return back()->withErrors(['exam' => 'لا توجد أسئلة في هذا الامتحان بعد.']);
        }

        $minutes = $exam->minutesFor($user);
        $deadline = $minutes ? now()->addMinutes($minutes) : null;
        $closes = $exam->closesAtFor($user);
        if ($closes && (! $deadline || $closes->lt($deadline))) {
            $deadline = $closes->copy();
        }
        // Random slots draw their questions now, for this student, never the same twice.
        $ids = collect();
        $drawn = [];
        foreach ($exam->questions()->get(['id', 'type', 'points', 'settings']) as $q) {
            if ($q->type !== Question::RANDOM) {
                $ids->push($q->id);

                continue;
            }
            $pool = Question::where('bank_category_id', $q->settings['category_id'] ?? 0)->whereNull('exam_id')
                ->where('type', '!=', Question::RANDOM)->whereNotIn('id', array_keys($drawn))->pluck('id')->shuffle()
                ->take((int) ($q->settings['count'] ?? 0));
            foreach ($pool as $id) {
                $drawn[$id] = $q->points;
                $ids->push($id);
            }
        }
        if ($exam->shuffle_questions) {
            $ids = $ids->shuffle();
        }

        $attempt = Attempt::create([
            'exam_id' => $exam->id,
            'user_id' => $user->id,
            'number' => $used + 1,
            'started_at' => now(),
            'deadline_at' => $deadline,
            'question_order' => $ids->values()->all(),
            'drawn' => $drawn ?: null,
            'ip' => $request->ip(),
        ]);
        ActivityLog::record('exam.started', "بدأ الامتحان \"{$exam->text('title')}\" (المحاولة {$attempt->number})", $exam);

        return redirect()->route('exams.attempts.take', $attempt);
    }

    public function take(Request $request, Attempt $attempt): Response|RedirectResponse
    {
        $this->own($request, $attempt);
        if ($attempt->isSubmitted() || $this->closeIfExpired($attempt)) {
            return redirect()->route('exams.attempts.result', $attempt);
        }
        if ($attempt->ip && $request->ip() !== $attempt->ip) {
            Event::create(['exam_attempt_id' => $attempt->id, 'type' => 'ip_changed', 'meta' => ['from' => $attempt->ip, 'to' => $request->ip()]]);
            $attempt->update(['ip' => $request->ip()]);
        }
        $exam = $attempt->exam;
        $answers = $attempt->answers()->get()->keyBy('exam_question_id');

        return Inertia::render('Exams/Take', [
            'attempt' => [
                'id' => $attempt->id,
                'number' => $attempt->number,
                'seconds_left' => $attempt->secondsLeft(),
            ],
            'exam' => ['id' => $exam->id, 'title' => $exam->text('title'), 'dir' => $this->presenter->dir($exam), 'lang' => $exam->languages[0] ?? 'ar'],
            'questions' => $attempt->orderedQuestions()->map(fn (Question $q) => $this->presenter->forStudent($q, $exam, $attempt))->values(),
            'answers' => $answers->map(fn (Answer $a) => ['response' => $a->response, 'flagged' => $a->flagged]),
        ]);
    }

    /** Autosave of one answer (and the «review later» flag). */
    public function save(Request $request, Attempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $data = $request->validate([
            'question_id' => ['required', 'integer'],
            'response' => ['nullable', 'array'],
            'response.text' => ['nullable', 'string', 'max:50000'],
            'flagged' => ['sometimes', 'boolean'],
        ]);
        if ($attempt->isSubmitted() || $attempt->isExpired(Attempt::GRACE_SECONDS)) {
            return response()->json(['message' => 'انتهى وقت المحاولة.', 'closed' => true], 409);
        }
        abort_unless(in_array((int) $data['question_id'], $attempt->question_order ?? [], true)
            || $attempt->exam->questions()->whereKey($data['question_id'])->exists(), 422);

        // The whole response (validated() would keep only its `text` key, the one with its own rule).
        // A file question's answer is set by upload() only, never by the page.
        $response = $request->input('response');
        $values = Question::whereKey($data['question_id'])->value('type') === Question::UPLOAD
            ? [] : ['response' => is_array($response) ? $response : null];
        if (array_key_exists('flagged', $data)) {
            $values['flagged'] = $data['flagged'];
        }
        Answer::updateOrCreate(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $data['question_id']], $values);

        return response()->json(['saved' => true, 'seconds_left' => $attempt->secondsLeft()]);
    }

    /** A file question's answer: the file replaces any earlier one. */
    public function upload(Request $request, Attempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $data = $request->validate(['question_id' => ['required', 'integer'], 'file' => ['required', 'file']]);
        if ($attempt->isSubmitted() || $attempt->isExpired(Attempt::GRACE_SECONDS)) {
            return response()->json(['message' => 'انتهى وقت المحاولة.', 'closed' => true], 409);
        }
        $question = $attempt->orderedQuestions()->firstWhere('id', (int) $data['question_id']);
        abort_unless($question && $question->type === Question::UPLOAD, 422);

        $file = $request->file('file');
        $maxMb = (int) ($question->settings['max_mb'] ?? 10);
        $types = array_filter(explode(',', (string) ($question->settings['types'] ?? '')));
        $ext = strtolower($file->getClientOriginalExtension());
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => "الملف أكبر من {$maxMb} ميغابايت."]);
        }
        if ($types && ! in_array($ext, $types, true)) {
            throw ValidationException::withMessages(['file' => 'نوع الملف غير مقبول؛ المقبول: '.implode('، ', $types).'.']);
        }
        if (in_array($ext, ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'sh', 'js', 'html', 'htm', 'svg'], true)) {
            throw ValidationException::withMessages(['file' => 'نوع الملف غير مقبول.']);
        }

        $answer = Answer::firstOrNew(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $question->id]);
        $old = $answer->response['file'] ?? null;
        $path = $file->storeAs(self::UPLOADS.'/'.$attempt->id, Str::uuid().($ext ? '.'.$ext : ''), 'local');
        $answer->response = ['file' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 200), 'size' => $file->getSize()];
        $answer->save();
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return response()->json(['name' => $answer->response['name'], 'size' => $answer->response['size'], 'seconds_left' => $attempt->secondsLeft()]);
    }

    public const UPLOADS = 'exam-uploads';

    /** A file a student uploaded, for them and the examiner. */
    public function file(Request $request, Answer $answer): StreamedResponse
    {
        $this->own($request, $answer->attempt, allowManager: true);
        $path = $answer->response['file'] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $answer->response['name'] ?? basename($path));
    }

    /** A question's picture. */
    public function image(string $name): BinaryFileResponse
    {
        abort_unless(ManageController::imageExists($name), 404);

        return response()->file(Storage::disk('local')->path(ManageController::IMAGES.'/'.$name), ['Cache-Control' => 'private, max-age=86400']);
    }

    /** The browser reports leaving the tab, pasting and so on; the examiner sees them with the attempt. */
    public function event(Request $request, Attempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $data = $request->validate(['type' => ['required', 'in:'.implode(',', Event::TYPES)], 'meta' => ['nullable', 'array']]);
        if (! $attempt->isSubmitted() && $attempt->events()->count() < 500) {
            Event::create(['exam_attempt_id' => $attempt->id, 'type' => $data['type'], 'meta' => $data['meta'] ?? null]);
        }

        return response()->json(['ok' => true]);
    }

    public function submit(Request $request, Attempt $attempt): RedirectResponse
    {
        $this->own($request, $attempt);
        if (! $attempt->isSubmitted()) {
            $this->finish($attempt);
            ActivityLog::record('exam.submitted', "سلّم الامتحان \"{$attempt->exam->text('title')}\"", $attempt->exam);
        }

        return redirect()->route('exams.attempts.result', $attempt);
    }

    public function result(Request $request, Attempt $attempt): Response
    {
        $this->own($request, $attempt, allowManager: true);
        $this->closeIfExpired($attempt);
        $exam = $attempt->exam;
        $user = $attempt->user;
        $visible = $exam->resultsVisibleTo($user) || $exam->isManageableBy($request->user());
        $answers = $attempt->answers()->get()->keyBy('exam_question_id');

        return Inertia::render('Exams/Result', [
            'exam' => ['id' => $exam->id, 'title' => $exam->text('title'), 'dir' => $this->presenter->dir($exam), 'pass_mark' => $exam->pass_mark],
            'attempt' => [
                'id' => $attempt->id,
                'number' => $attempt->number,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'score' => $visible ? $attempt->score : null,
                'max_score' => $attempt->max_score,
                'percent' => $visible ? $attempt->percent() : null,
                'needs_grading' => $attempt->needs_grading,
            ],
            'visible' => $visible,
            'review' => $visible
                ? $attempt->orderedQuestions()->filter(fn (Question $q) => $q->isGraded())
                    ->map(fn (Question $q) => $this->presenter->review($q, $exam, $answers[$q->id] ?? null, $exam->show_answers))->values()
                : [],
        ]);
    }

    private function own(Request $request, Attempt $attempt, bool $allowManager = false): void
    {
        $user = $request->user();
        if ($attempt->user_id === $user->id) {
            return;
        }
        abort_unless($allowManager && $attempt->exam->isManageableBy($user), 404);
    }

    /** Submits an attempt whose time is up. True when it is (now) closed. */
    private function closeIfExpired(Attempt $attempt): bool
    {
        if ($attempt->isSubmitted()) {
            return true;
        }
        if (! $attempt->isExpired(Attempt::GRACE_SECONDS)) {
            return false;
        }
        $this->finish($attempt, $attempt->deadline_at);

        return true;
    }

    private function finish(Attempt $attempt, $at = null): void
    {
        DB::transaction(function () use ($attempt, $at) {
            $locked = Attempt::whereKey($attempt->id)->lockForUpdate()->first();
            if ($locked->isSubmitted()) {
                return;
            }
            $locked->update(['submitted_at' => $at ?? now()]);
            $this->grader->grade($locked);
        });
        $attempt->refresh();
    }
}
