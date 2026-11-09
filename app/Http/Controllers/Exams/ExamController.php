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
        $exams = Exam::published()->assignedTo($user)->withCount('questions')
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
        abort_unless(($exam->is_published && $exam->isAssignedTo($user)) || $exam->isManageableBy($user), 404);
        $exam->load(['attempts' => fn ($q) => $q->where('user_id', $user->id)->orderBy('number'), 'overrides' => fn ($q) => $q->where('user_id', $user->id)])
            ->loadCount('questions');
        $exam->attempts->each(fn (Attempt $a) => $this->closeIfExpired($a));
        $visible = $exam->resultsVisibleTo($user);

        return Inertia::render('Exams/Show', [
            'exam' => $this->presenter->card($exam, $user) + [
                'description' => $exam->text('description'),
                'needs_password' => filled($exam->password),
                'needs_access_code' => filled($exam->access_code),
                'in_network' => $exam->networkAllows($request),
                'seb_required' => $exam->seb_required,
                'in_seb' => $exam->sebAllows($request),
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
        abort_unless($exam->is_published && $exam->isAssignedTo($user), 404);
        if (! $exam->sebAllows($request)) {
            return back()->withErrors(['exam' => self::SEB_MESSAGE]);
        }
        if (! $exam->networkAllows($request)) {
            return back()->withErrors(['exam' => self::NETWORK_MESSAGE]);
        }
        if ($wrongPc = $exam->computerAllows($user, $request)) {
            return back()->withErrors(['exam' => $wrongPc]);
        }
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
        if (filled($exam->access_code) && mb_strtoupper(trim((string) $request->input('access_code'))) !== mb_strtoupper($exam->access_code)) {
            return back()->withErrors(['access_code' => 'رمز الدخول غير صحيح. يعلنه المراقب في القاعة.']);
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
            $pool = Question::randomPool($q->settings ?? [])->diff(array_keys($drawn))->shuffle()
                ->take((int) ($q->settings['count'] ?? 0));
            foreach ($pool as $id) {
                $drawn[$id] = $q->points;
                $ids->push($id);
            }
        }
        // Versions A/B/C: neighbouring seats get different versions, each version one fixed order
        // (A as written unless questions are shuffled anyway).
        $variant = $exam->variants > 1 ? $this->variantFor($exam, $user) : null;
        if ($variant && ($variant > 1 || $exam->shuffle_questions)) {
            $ids = $ids->sortBy(fn ($id) => crc32('v'.$exam->id.'-'.$variant.'-q-'.$id))->values();
        } elseif ($exam->shuffle_questions) {
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
            'variant' => $variant,
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
        if (! $exam->sebAllows($request)) {
            return redirect()->route('exams.show', $exam)->withErrors(['exam' => self::SEB_MESSAGE]);
        }
        if (! $exam->networkAllows($request)) {
            return redirect()->route('exams.show', $exam)->withErrors(['exam' => self::NETWORK_MESSAGE]);
        }
        if ($wrongPc = $exam->computerAllows($request->user(), $request)) {
            return redirect()->route('exams.show', $exam)->withErrors(['exam' => $wrongPc]);
        }
        // With the resume code on, the attempt belongs to the page that opened it: opened again
        // (another tab, a reload, another computer) it locks until the invigilator's code.
        if ($exam->resume_code_required && ! $attempt->locked_at) {
            $sessionKey = 'exam_key.'.$attempt->id;
            if ($attempt->session_key === null) {
                $attempt->update(['session_key' => Str::random(40)]);
                $request->session()->put($sessionKey, $attempt->session_key);
            } elseif (! hash_equals($attempt->session_key, (string) $request->session()->get($sessionKey))) {
                $attempt->lock('reopened');
            }
        }
        $answers = $attempt->answers()->get()->keyBy('exam_question_id');
        $questions = $attempt->orderedQuestions();

        return Inertia::render('Exams/Take', [
            'attempt' => [
                'id' => $attempt->id,
                'number' => $attempt->number,
                'seconds_left' => $attempt->secondsLeft(),
            ],
            'exam' => ['id' => $exam->id, 'title' => $exam->text('title'), 'dir' => $this->presenter->dir($exam), 'lang' => $exam->languages[0] ?? 'ar', 'practice' => $exam->is_practice,
                'sequential' => $exam->navigation === 'sequential', 'calculator' => $exam->calculator, 'position' => $attempt->position],
            'hall' => [
                'locked' => $attempt->locked_at !== null,
                'help' => $attempt->help_requested_at !== null,
                'computer' => $exam->computerFor($request->user())?->label,
                'variant' => $attempt->variantLetter(),
                // The questions page carries the student's name across it and blocks copying.
                'protect' => $exam->protect_content && ! $exam->is_practice,
                'watermark' => trim($request->user()->name.' · '.($request->user()->student_number ?: $request->user()->email)),
            ],
            'questions' => $questions->map(fn (Question $q) => $this->presenter->forStudent($q, $exam, $attempt))->values(),
            'offline_token' => $attempt->offlineToken(),
            'answers' => $answers->map(fn (Answer $a) => ['response' => $a->response, 'flagged' => $a->flagged,
                'checked' => $a->checked_at && ($q = $questions->firstWhere('id', $a->exam_question_id)) ? $this->checkResult($q, $exam, $a) : null]),
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
        if ($attempt->locked_at) {
            return response()->json(['message' => 'المحاولة مقفلة حتى رمز المراقب.', 'locked' => true], 423);
        }
        // No going back: a question passed is closed.
        if ($attempt->exam->navigation === 'sequential') {
            $index = array_search((int) $data['question_id'], array_map('intval', $attempt->question_order ?? []), true);
            if ($index !== false && $index < $attempt->position) {
                return response()->json(['message' => 'لا رجوع إلى سؤال سابق في هذا الامتحان.', 'passed' => true], 422);
            }
        }
        abort_unless(in_array((int) $data['question_id'], $attempt->question_order ?? [], true)
            || $attempt->exam->questions()->whereKey($data['question_id'])->exists(), 422);

        // The whole response (validated() would keep only its `text` key, the one with its own rule).
        // A file question's answer is set by upload() only, never by the page; a checked practice answer is final.
        $response = $request->input('response');
        $checked = Answer::where('exam_attempt_id', $attempt->id)->where('exam_question_id', $data['question_id'])->whereNotNull('checked_at')->exists();
        // Only the flag sent (no response key): the answer is left as it is.
        $values = $checked || ! $request->exists('response') || in_array(Question::whereKey($data['question_id'])->value('type'), Question::FILE_TYPES, true)
            ? [] : ['response' => is_array($response) ? $response : null];
        if (array_key_exists('flagged', $data)) {
            $values['flagged'] = $data['flagged'];
        }
        $before = Answer::where('exam_attempt_id', $attempt->id)->where('exam_question_id', $data['question_id'])->first()?->response;
        Answer::updateOrCreate(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $data['question_id']], $values);
        // The audit log: every change of the answer, with its time and address.
        if (array_key_exists('response', $values) && $values['response'] != $before) {
            \App\Models\Exams\AnswerLog::record($attempt, (int) $data['question_id'], $values['response'], 'page', $request->ip());
        }
        $attempt->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json(['saved' => true, 'seconds_left' => $attempt->secondsLeft()]);
    }

    /**
     * The page's heartbeat: the attempt is still open (the invigilator sees it as connected),
     * the deadline as it is now (the invigilator may have given more time), and the hall's
     * announcements. A closed attempt (time up, or submitted by the invigilator) says so.
     */
    public function ping(Request $request, Attempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        if ($attempt->isSubmitted() || $this->closeIfExpired($attempt)) {
            return response()->json(['closed' => true]);
        }
        $attempt->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json([
            'closed' => false,
            'locked' => $attempt->locked_at !== null,
            'help' => $attempt->help_requested_at !== null,
            'seconds_left' => $attempt->secondsLeft(),
            'announcements' => DB::table('exam_announcements')->where('exam_id', $attempt->exam_id)
                ->where('created_at', '>=', $attempt->started_at)->orderBy('id')->get(['id', 'body', 'created_at']),
        ]);
    }

    /** A student asks for a question's mark to be looked at again (one open request per question). */
    public function appeal(Request $request, Attempt $attempt): RedirectResponse
    {
        $this->own($request, $attempt);
        $exam = $attempt->exam;
        abort_unless($exam->appeals_open && $attempt->isSubmitted() && $exam->resultsVisibleTo($request->user()), 403);
        $data = $request->validate(['question_id' => ['nullable', 'integer'], 'body' => ['required', 'string', 'min:5', 'max:3000']]);
        $qid = isset($data['question_id']) && $attempt->orderedQuestions()->contains('id', (int) $data['question_id']) ? (int) $data['question_id'] : null;
        if (\App\Models\Exams\Appeal::where('exam_attempt_id', $attempt->id)->where('exam_question_id', $qid)->where('status', 'open')->exists()) {
            return back()->withErrors(['appeal' => 'لك طلب مفتوح على هذا السؤال؛ انتظر الرد.']);
        }
        \App\Models\Exams\Appeal::create(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $qid, 'body' => $data['body']]);

        return back()->with('success', 'أُرسل اعتراضك إلى الأستاذ.');
    }

    /** Sequential exams: the student moved on; questions before this one close. */
    public function advance(Request $request, Attempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $to = (int) $request->validate(['to' => ['required', 'integer', 'min:0']])['to'];
        if (! $attempt->isSubmitted()) {
            $attempt->update(['position' => max($attempt->position, min($to, count($attempt->question_order ?? []) - 1))]);
        }

        return response()->json(['position' => $attempt->position]);
    }

    /** The invigilator's code opens a locked attempt again, on this page. */
    public function unlock(Request $request, Attempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:10']])['code'];
        if ($attempt->locked_at && ! hash_equals((string) $attempt->resume_code, trim(strtr($code, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])))) {
            return response()->json(['message' => 'الرمز غير صحيح.'], 422);
        }
        $attempt->unlock();
        $attempt->update(['session_key' => Str::random(40)]);
        $request->session()->put('exam_key.'.$attempt->id, $attempt->session_key);

        return response()->json(['locked' => false]);
    }

    /** «I need help»: the invigilator sees a raised hand by the student's name and computer. */
    public function help(Request $request, Attempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $on = $request->boolean('on');
        $attempt->update(['help_requested_at' => $on ? now() : null]);

        return response()->json(['help' => $on]);
    }

    public const NETWORK_MESSAGE = 'هذا الامتحان يُؤدّى من أجهزة القاعة فقط.';

    public const SEB_MESSAGE = 'هذا الامتحان يُؤدّى في متصفح Safe Exam Browser فقط، وبإعداداته المعتمدة.';

    /** Practice: marks one answer now and shows the right one; the answer cannot change after. */
    public function check(Request $request, Attempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $exam = $attempt->exam;
        abort_unless($exam->is_practice, 404);
        $data = $request->validate(['question_id' => ['required', 'integer'], 'response' => ['nullable', 'array']]);
        if ($attempt->isSubmitted() || $attempt->isExpired(Attempt::GRACE_SECONDS)) {
            return response()->json(['message' => 'انتهى وقت المحاولة.', 'closed' => true], 409);
        }
        if ($attempt->locked_at) {
            return response()->json(['locked' => true], 423);
        }
        $question = $attempt->orderedQuestions()->firstWhere('id', (int) $data['question_id']);
        abort_unless($question && $question->isGraded() && ! $question->isManual(), 422);

        $answer = Answer::firstOrNew(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $question->id]);
        if (! $answer->checked_at) {
            $response = $request->input('response');
            $answer->response = is_array($response) ? $response : null;
            $answer->checked_at = now();
            $answer->save();
        }

        return response()->json($this->checkResult($question, $exam, $answer));
    }

    private function checkResult(Question $question, Exam $exam, Answer $answer): array
    {
        $fraction = $this->grader->fraction($question, $answer->response, $answer->exam_attempt_id);
        $review = $this->presenter->review($question, $exam, null, true, $answer->exam_attempt_id);

        return [
            'fraction' => round($fraction, 2),
            'correct' => $fraction >= 0.999 ? null : $review['correct'],
            'feedback' => $review['feedback'],
            'zones' => $review['zones'] ?? [],
        ];
    }

    /** A file question's answer: the file replaces any earlier one. */
    public function upload(Request $request, Attempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $data = $request->validate(['question_id' => ['required', 'integer'], 'file' => ['required', 'file']]);
        if ($attempt->isSubmitted() || $attempt->isExpired(Attempt::GRACE_SECONDS)) {
            return response()->json(['message' => 'انتهى وقت المحاولة.', 'closed' => true], 409);
        }
        if ($attempt->locked_at) {
            return response()->json(['locked' => true], 423);
        }
        $question = $attempt->orderedQuestions()->firstWhere('id', (int) $data['question_id']);
        abort_unless($question && in_array($question->type, Question::FILE_TYPES, true), 422);

        $file = $request->file('file');
        // A recording or a drawing comes from the page itself, in its own format.
        [$maxMb, $types] = match ($question->type) {
            Question::AUDIO => [25, ['webm', 'ogg', 'oga', 'mp4', 'm4a', 'wav', 'mp3']],
            Question::DRAWING => [8, ['png']],
            default => [(int) ($question->settings['max_mb'] ?? 10), array_filter(explode(',', (string) ($question->settings['types'] ?? '')))],
        };
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
        $attempt = $answer->attempt;
        abort_unless($attempt->user_id === $request->user()->id || $attempt->exam->isMarkableBy($request->user()), 404);
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
        if ($data['type'] === 'hidden' && $attempt->exam->resume_code_required) {
            $attempt->lock('left');
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
                'receipt' => $attempt->receipt(),
                'answered' => $answers->filter(fn ($a) => filled($a->response))->count(),
                'questions' => count($attempt->question_order ?? []),
                'student' => $user->name,
                'student_number' => $user->student_number,
            ],
            'visible' => $visible,
            'appeals_open' => $exam->appeals_open && $visible && $attempt->isSubmitted() && $attempt->user_id === $request->user()->id,
            'appeals' => \App\Models\Exams\Appeal::where('exam_attempt_id', $attempt->id)->latest('id')->get()
                ->map(fn ($ap) => ['id' => $ap->id, 'question_id' => $ap->exam_question_id, 'body' => $ap->body, 'status' => $ap->status, 'reply' => $ap->reply]),
            'review' => $visible
                ? $attempt->orderedQuestions()->filter(fn (Question $q) => $q->isGraded())
                    ->map(fn (Question $q) => $this->presenter->review($q, $exam, $answers[$q->id] ?? null, $exam->show_answers, $attempt->id))->values()
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

    /**
     * The version for a student: by the place of their computer in its room (so the computers
     * side by side alternate A, B, C…), or by the order students start when nobody is seated.
     */
    private function variantFor(Exam $exam, \App\Models\User $user): int
    {
        $n = max(1, min(4, $exam->variants));
        if (($pc = $exam->computerFor($user)) && $pc->seat_row && $pc->seat_col) {
            // On the hall map: no two seats side by side or one behind the other share a version.
            return ($pc->seat_row + $pc->seat_col) % $n + 1;
        }
        if ($pc) {
            $labels = \App\Models\Exams\Computer::where('room', $pc->room)->pluck('label')->sort(SORT_NATURAL)->values();

            return ((int) $labels->search($pc->label)) % $n + 1;
        }

        return $exam->attempts()->count() % $n + 1;
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
