<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Services\Exams\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The examiner's side: writing exams and their questions. */
class ManageController extends Controller
{
    public function __construct(private Presenter $presenter) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        // Their own exams (all of them for an admin), and those they were invited to mark.
        $exams = Exam::query()->when(! $user->isAdmin(), fn ($q) => $q->where(fn ($w) => $w->where('user_id', $user->id)
            ->orWhereHas('markers', fn ($m) => $m->whereKey($user->id))))
            ->withCount(['questions', 'attempts'])
            ->withCount(['attempts as pending_count' => fn ($q) => $q->where('needs_grading', true)])
            ->with('author:id,name')->latest('id')->get();

        return Inertia::render('Exams/Manage/Index', [
            'to_review' => Exam::where('reviewer_id', $user->id)->where('review_status', 'pending')->with('author:id,name')->withCount('questions')->latest('id')->get()
                ->map(fn (Exam $e) => ['id' => $e->id, 'title' => $e->text('title'), 'author' => $e->author?->name, 'questions' => $e->questions_count, 'note' => $e->review_note]),
            'exams' => $exams->map(fn (Exam $e) => [
                'id' => $e->id,
                'title' => $e->text('title'),
                'subject' => $e->subject,
                'stage' => $e->stage,
                'author' => $e->author?->name,
                'can_edit' => $e->isManageableBy($user),
                'is_published' => $e->is_published,
                'review' => $e->reviewer_id ? $e->review_status : null,
                'questions' => $e->questions_count,
                'attempts' => $e->attempts_count,
                'pending' => $e->pending_count,
                'opens_at' => $e->opens_at?->toIso8601String(),
                'closes_at' => $e->closes_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $lang = in_array(app()->getLocale(), Exam::LANGUAGES, true) ? app()->getLocale() : 'ar';
        $exam = Exam::create([
            'user_id' => $request->user()->id,
            'title' => [$lang => $lang === 'ar' ? 'امتحان جديد' : 'New exam'],
            'languages' => [$lang],
            'duration_minutes' => 60,
        ]);
        ActivityLog::record('exam.created', 'أنشأ امتحاناً جديداً', $exam);

        return redirect()->route('exams.manage.edit', $exam);
    }

    public function edit(Request $request, Exam $exam): Response
    {
        $this->authorizeExam($request, $exam);

        return Inertia::render('Exams/Manage/Edit', [
            'exam' => $this->presenter->forEditor($exam),
            'groups' => \App\Models\Exams\Group::withCount('members')->orderBy('name')->get()
                ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'stage' => $g->stage, 'members' => $g->members_count]),
            'attempts' => $exam->attempts()->count(),
            'languages' => Exam::LANGUAGES,
            'types' => Question::TYPES,
            'categories' => \App\Models\Exams\BankCategory::usableBy($request->user())->withCount('questions')->orderBy('name')->get(['id', 'name', 'subject', 'user_id', 'is_shared']),
            'templates' => \App\Models\Exams\Template::where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name', 'settings']),
            'halls' => \App\Models\Exams\Computer::whereNotNull('room')->distinct()->orderBy('room')->pluck('room')
                ->merge(Exam::whereNotNull('hall')->distinct()->pluck('hall'))->unique()->values(),
            // Exams that can be required first (a practice run of the interface, usually).
            'prerequisites' => Exam::where('id', '!=', $exam->id)->when(! $request->user()->isAdmin(), fn ($q) => $q->where('user_id', $request->user()->id))
                ->orderByDesc('is_practice')->latest('id')->limit(200)->get(['id', 'title', 'is_practice'])->map(fn (Exam $e) => ['id' => $e->id, 'title' => $e->text('title'), 'practice' => $e->is_practice]),
        ]);
    }

    /** Saves the settings now in the editor as one of the teacher's templates (same name: replaced). */
    public function storeTemplate(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'settings' => ['required', 'array']]);
        $template = \App\Models\Exams\Template::updateOrCreate(['user_id' => $request->user()->id, 'name' => trim($data['name'])],
            ['settings' => collect($data['settings'])->only(\App\Models\Exams\Template::KEYS)->all()]);

        return response()->json($template->only('id', 'name', 'settings'));
    }

    public function destroyTemplate(Request $request, \App\Models\Exams\Template $template): \Illuminate\Http\JsonResponse
    {
        abort_unless($template->user_id === $request->user()->id, 404);
        $template->delete();

        return response()->json(['ok' => true]);
    }

    public function update(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizeExam($request, $exam);
        $data = $request->validate([
            'title' => ['required', 'array'],
            'description' => ['nullable', 'array'],
            'subject' => ['nullable', 'string', 'max:255'],
            'stage' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:255'],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => [Rule::in(Exam::LANGUAGES)],
            'direction' => ['required', 'in:auto,rtl,ltr'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:100'],
            'grading_method' => ['required', Rule::in(Exam::GRADING)],
            'pass_mark' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'password' => ['nullable', 'string', 'max:100'],
            'shuffle_questions' => ['boolean'],
            'shuffle_choices' => ['boolean'],
            'show_results' => ['required', Rule::in(Exam::SHOW_RESULTS)],
            'show_answers' => ['boolean'],
            'is_published' => ['boolean'],
            'is_practice' => ['boolean'],
            'seb_required' => ['boolean'],
            'seb_keys' => ['nullable', 'string', 'max:5000'],
            'double_marking' => ['boolean'],
            'access_code' => ['nullable', 'string', 'max:20'],
            'resume_code_required' => ['boolean'],
            'navigation' => ['nullable', 'in:free,sequential'],
            'results_at' => ['nullable', 'date', 'required_if:show_results,at_date'],
            'anonymous_marking' => ['boolean'],
            'appeals_open' => ['boolean'],
            'calculator' => ['nullable', 'in:none,basic,scientific'],
            'questions.*.tags' => ['nullable', 'array', 'max:10'],
            'questions.*.difficulty' => ['nullable', 'integer'],
            'bind_computers' => ['boolean'],
            'variants' => ['nullable', 'integer', 'min:1', 'max:4'],
            'protect_content' => ['boolean'],
            'reviewer_email' => ['nullable', 'email'],
            'is_assignment' => ['boolean'],
            'adaptive' => ['nullable', 'array'],
            'adaptive.category_id' => ['required_with:adaptive', 'integer', 'exists:exam_bank_categories,id'],
            'adaptive.count' => ['required_with:adaptive', 'integer', 'min:2', 'max:200'],
            'adaptive.points' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'adaptive.tag' => ['nullable', 'string', 'max:100'],
            'hall' => ['nullable', 'string', 'max:100'],
            'sessions' => ['nullable', 'array', 'max:10'],
            'sessions.*.label' => ['nullable', 'string', 'max:50'],
            'sessions.*.opens_at' => ['required', 'date'],
            'sessions.*.closes_at' => ['required', 'date'],
            'resources' => ['nullable', 'array', 'max:20'],
            'resources.*.name' => ['required', 'string', 'max:150'],
            'resources.*.file' => ['required', 'string', 'regex:/^[a-f0-9]{40}\.pdf$/'],
            'requires_exam_id' => ['nullable', 'integer', 'exists:exams,id'],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'exists:exam_groups,id'],
            'marking_tolerance' => ['nullable', 'integer', 'min:0', 'max:100'],
            'questions' => ['array', 'max:500'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.type' => ['required', Rule::in(Question::TYPES)],
            'questions.*.format' => ['nullable', 'in:text,html'],
            'questions.*.body' => ['nullable', 'array'],
            'questions.*.feedback' => ['nullable', 'array'],
            'questions.*.points' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'questions.*.settings' => ['nullable', 'array'],
            'questions.*.choices' => ['nullable', 'array', 'max:50'],
            'questions.*.choices.*.id' => ['nullable', 'integer'],
            'questions.*.choices.*.body' => ['nullable', 'array'],
            'questions.*.choices.*.match' => ['nullable', 'array'],
            'questions.*.choices.*.is_correct' => ['nullable', 'boolean'],
        ]);

        $langs = array_values(array_unique($data['languages']));
        $title = Exam::cleanTexts($data['title'], $langs);
        if ($title === []) {
            throw ValidationException::withMessages(['title' => 'اكتب عنوان الامتحان.']);
        }
        $questions = self::checkQuestions($data['questions'] ?? [], $langs);
        $bad = collect(preg_split('/[\s,،]+/u', trim((string) ($data['allowed_ips'] ?? '')), -1, PREG_SPLIT_NO_EMPTY))
            ->reject(fn ($r) => self::validRange($r));
        if ($bad->isNotEmpty()) {
            throw ValidationException::withMessages(['allowed_ips' => 'عناوين غير صالحة: '.$bad->implode('، ').' (مثال: 192.168.1.0/24).']);
        }

        // Sittings: each ends after it starts; numbered by their order.
        $sessions = collect($data['sessions'] ?? [])->values()->map(fn ($s, $i) => ['label' => trim((string) ($s['label'] ?? '')) ?: 'الجلسة '.($i + 1),
            'opens_at' => \Illuminate\Support\Carbon::parse($s['opens_at'])->toIso8601String(), 'closes_at' => \Illuminate\Support\Carbon::parse($s['closes_at'])->toIso8601String()]);
        if ($sessions->contains(fn ($s) => $s['closes_at'] <= $s['opens_at'])) {
            throw ValidationException::withMessages(['sessions' => 'كل جلسة تنتهي بعد أن تبدأ.']);
        }
        $exam->sessions = $sessions->isEmpty() ? null : $sessions->all();
        $exam->resources = collect($data['resources'] ?? [])->map(fn ($r) => ['name' => $r['name'], 'file' => $r['file']])->values()->all() ?: null;
        if (($data['requires_exam_id'] ?? null) == $exam->id) {
            throw ValidationException::withMessages(['requires_exam_id' => 'لا يكون الامتحان شرطاً لنفسه.']);
        }
        $exam->requires_exam_id = $data['requires_exam_id'] ?? null;
        // Adaptive: questions come from the bank one by one, so the exam runs forward only.
        $exam->adaptive = ! empty($data['adaptive']['category_id']) ? ['category_id' => (int) $data['adaptive']['category_id'], 'count' => (int) $data['adaptive']['count'],
            'points' => (float) ($data['adaptive']['points'] ?? 1), 'tag' => trim((string) ($data['adaptive']['tag'] ?? ''))] : null;
        if ($exam->adaptive) {
            $data['navigation'] = 'sequential';
        }

        // The reviewer: another teacher (or an admin) who approves the questions before publishing.
        $reviewer = null;
        if (filled($data['reviewer_email'] ?? null)) {
            $reviewer = \App\Models\User::where('email', mb_strtolower(trim($data['reviewer_email'])))->first();
            if (! $reviewer || ! $reviewer->canManageExams() || $reviewer->id === $exam->user_id) {
                throw ValidationException::withMessages(['reviewer_email' => 'المراجع يجب أن يكون أستاذاً آخر له حساب في النظام.']);
            }
        }

        DB::transaction(function () use ($exam, $data, $langs, $title, $questions) {
            $exam->update([
                'title' => $title,
                'description' => Exam::cleanTexts($data['description'] ?? [], $langs) ?: null,
                'languages' => $langs,
            ] + collect($data)->only([
                'subject', 'stage', 'department', 'direction', 'duration_minutes', 'opens_at', 'closes_at', 'max_attempts',
                'grading_method', 'pass_mark', 'password', 'shuffle_questions', 'shuffle_choices', 'show_results', 'show_answers', 'is_published',
                'is_practice', 'seb_required', 'seb_keys', 'double_marking', 'access_code', 'allowed_ips', 'resume_code_required', 'bind_computers', 'results_at', 'anonymous_marking', 'appeals_open', 'protect_content', 'is_assignment', 'hall',
            ])->all() + array_filter(['navigation' => $data['navigation'] ?? null, 'calculator' => $data['calculator'] ?? null, 'variants' => $data['variants'] ?? null]) + (isset($data['marking_tolerance']) ? ['marking_tolerance' => $data['marking_tolerance']] : []));

            $existing = $exam->questions()->with('choices')->get()->keyBy('id');
            $keep = [];
            foreach ($questions as $i => $q) {
                $question = isset($q['id'], $existing[$q['id']]) ? $existing[$q['id']] : new Question(['exam_id' => $exam->id]);
                $question->fill([
                    'type' => $q['type'], 'format' => $q['format'] ?? 'text', 'tags' => $q['tags'] ?: null, 'difficulty' => $q['difficulty'] ?? null, 'body' => $q['body'], 'feedback' => $q['feedback'] ?: null,
                    'points' => $q['type'] === Question::DESCRIPTION ? 0 : $q['points'],
                    'settings' => $q['settings'] ?: null, 'position' => $i,
                ])->save();
                $keep[] = $question->id;

                $old = $question->choices->keyBy('id');
                $keepChoices = [];
                foreach ($q['choices'] as $j => $c) {
                    $choice = isset($c['id'], $old[$c['id']]) ? $old[$c['id']] : new Choice(['exam_question_id' => $question->id]);
                    $choice->fill(['body' => $c['body'], 'match' => $c['match'] ?: null, 'is_correct' => $c['is_correct'], 'position' => $j])->save();
                    $keepChoices[] = $choice->id;
                }
                $question->choices()->whereNotIn('id', $keepChoices)->delete();
            }
            $exam->questions()->whereNotIn('id', $keep)->delete();
            $exam->groups()->sync($data['group_ids'] ?? []);
        });

        // Review: a new reviewer, or questions changed since the approval, means a new review;
        // until then the exam stays unpublished.
        $exam->refresh();
        if (array_key_exists('reviewer_email', $data)) {
            if (! $reviewer) {
                $exam->update(['reviewer_id' => null, 'review_status' => null, 'review_note' => null, 'reviewed_at' => null, 'review_hash' => null]);
            } elseif ($reviewer->id !== $exam->reviewer_id) {
                $exam->update(['reviewer_id' => $reviewer->id, 'review_status' => 'pending', 'review_note' => null, 'reviewed_at' => null, 'review_hash' => null]);
            }
        }
        if ($exam->review_status === 'approved' && $exam->review_hash !== $exam->questionsHash()) {
            $exam->update(['review_status' => 'pending', 'review_note' => 'تغيّرت الأسئلة بعد الموافقة.']);
        }
        ActivityLog::record('exam.updated', "حدّث الامتحان \"{$exam->text('title')}\"", $exam);
        if ($exam->is_published && $exam->awaitsReview()) {
            $exam->update(['is_published' => false]);

            return back()->with('success', 'تم حفظ الامتحان.')
                ->withErrors(['is_published' => 'حُفظ الامتحان، ولن يُنشر حتى يوافق المراجع على أسئلته.']);
        }

        return back()->with('success', 'تم حفظ الامتحان.');
    }

    /**
     * The department's exam timetable: every exam (and each of its sittings) by day, with its
     * hall and groups, and the clashes — the same hall, or the same group, at the same time.
     */
    public function schedule(Request $request): Response
    {
        $user = $request->user();
        $from = $request->filled('from') ? \Illuminate\Support\Carbon::parse($request->input('from'))->startOfDay() : now()->subDays(7)->startOfDay();
        $to = $from->copy()->addDays(120);
        $entries = collect();
        foreach (Exam::with(['groups:id,name', 'author:id,name'])->where(fn ($q) => $q->whereNotNull('opens_at')->orWhereNotNull('sessions'))->get() as $e) {
            $slots = $e->sessions
                ? collect($e->sessions)->map(fn ($s) => [$s['label'] ?? null, \Illuminate\Support\Carbon::parse($s['opens_at']), \Illuminate\Support\Carbon::parse($s['closes_at'])])
                : collect([[null, $e->opens_at, $e->closes_at ?? $e->opens_at->copy()->addMinutes($e->duration_minutes ?: 60)]]);
            foreach ($slots as [$label, $start, $end]) {
                if ($end->lt($from) || $start->gt($to)) {
                    continue;
                }
                $entries->push(['exam' => $e, 'id' => $e->id.'-'.$entries->count(), 'label' => $label, 'start' => $start, 'end' => $end]);
            }
        }
        $entries = $entries->sortBy('start')->values();
        $clashes = [];
        foreach ($entries as $i => $a) {
            foreach ($entries->slice($i + 1) as $b) {
                if ($b['start']->gte($a['end'])) {
                    break;
                }
                if ($a['exam']->id === $b['exam']->id) {
                    continue;
                }
                $why = [];
                if (filled($a['exam']->hall) && mb_strtolower(trim($a['exam']->hall)) === mb_strtolower(trim((string) $b['exam']->hall))) {
                    $why[] = 'القاعة '.$a['exam']->hall;
                }
                $shared = $a['exam']->groups->pluck('name', 'id')->intersectByKeys($b['exam']->groups->pluck('name', 'id'));
                if ($shared->isNotEmpty()) {
                    $why[] = 'المجموعة '.$shared->implode('، ');
                }
                foreach ($why ? [$a['id'] => $b, $b['id'] => $a] : [] as $id => $other) {
                    $clashes[$id][] = ['with' => $other['exam']->text('title'), 'why' => implode(' و', $why)];
                }
            }
        }

        return Inertia::render('Exams/Manage/Schedule', [
            'from' => $from->toDateString(),
            'entries' => $entries->map(fn ($x) => [
                'id' => $x['id'], 'exam_id' => $x['exam']->id, 'title' => $x['exam']->text('title'), 'session' => $x['label'],
                'start' => $x['start']->toIso8601String(), 'end' => $x['end']->toIso8601String(), 'hall' => $x['exam']->hall,
                'groups' => $x['exam']->groups->pluck('name'), 'author' => $x['exam']->author?->name, 'subject' => $x['exam']->subject,
                'published' => $x['exam']->is_published, 'can_edit' => $x['exam']->isManageableBy($user), 'clashes' => $clashes[$x['id']] ?? [],
            ]),
        ]);
    }

    /** A PDF an open-book exam allows (kept privately, named by its content). */
    public function uploadResource(Request $request, Exam $exam): JsonResponse
    {
        $this->authorizeExam($request, $exam);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:51200']]);
        $file = $request->file('file');
        $name = sha1_file($file->getRealPath()).'.pdf';
        \Illuminate\Support\Facades\Storage::disk('local')->putFileAs('exam-resources', $file, $name);

        return response()->json(['name' => \Illuminate\Support\Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 150, ''), 'file' => $name]);
    }

    /** The teacher's own look at an allowed file. */
    public function resource(Request $request, Exam $exam, string $file): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeExam($request, $exam);

        return self::streamResource($exam, $file);
    }

    public static function streamResource(Exam $exam, string $file): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless(collect($exam->resources ?? [])->contains('file', $file) && \Illuminate\Support\Facades\Storage::disk('local')->exists('exam-resources/'.$file), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response('exam-resources/'.$file, null, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline', 'Cache-Control' => 'private, no-store', 'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    /** The reviewer approves the questions as they are now, or returns them with a note. */
    public function review(Request $request, Exam $exam): RedirectResponse
    {
        abort_unless($exam->reviewer_id === $request->user()->id, 404);
        $data = $request->validate(['decision' => ['required', 'in:approve,return'], 'note' => ['nullable', 'string', 'max:5000', 'required_if:decision,return']]);
        $approve = $data['decision'] === 'approve';
        $exam->update([
            'review_status' => $approve ? 'approved' : 'returned', 'review_note' => $data['note'] ?? null, 'reviewed_at' => now(),
            'review_hash' => $approve ? $exam->questionsHash() : null,
        ]);
        ActivityLog::record('exam.reviewed', ($approve ? 'وافق على أسئلة' : 'أعاد للتعديل أسئلة')." \"{$exam->text('title')}\"", $exam);

        return back()->with('success', $approve ? 'تمت الموافقة على الأسئلة؛ يستطيع صاحب الامتحان نشره.' : 'أُعيدت الأسئلة لصاحبها مع ملاحظتك.');
    }

    public function destroy(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizeExam($request, $exam);
        $title = $exam->text('title');
        $exam->delete();
        ActivityLog::record('exam.deleted', "حذف الامتحان \"{$title}\"");

        return redirect()->route('exams.manage.index')->with('success', 'تم حذف الامتحان.');
    }

    public function duplicate(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorizeExam($request, $exam);
        $copy = DB::transaction(function () use ($exam, $request) {
            $copy = $exam->replicate(['is_published']);
            $copy->user_id = $request->user()->id;
            $copy->title = collect($exam->title)->map(fn ($t) => $t.' (نسخة)')->all();
            $copy->is_published = false;
            $copy->forceFill(['reviewer_id' => null, 'review_status' => null, 'review_note' => null, 'reviewed_at' => null, 'review_hash' => null]);
            $copy->save();
            foreach ($exam->questions()->with('choices')->get() as $q) {
                $nq = $q->replicate();
                $nq->exam_id = $copy->id;
                $nq->save();
                foreach ($q->choices as $c) {
                    $nc = $c->replicate();
                    $nc->exam_question_id = $nq->id;
                    $nc->save();
                }
            }

            return $copy;
        });

        return redirect()->route('exams.manage.edit', $copy)->with('success', 'تم نسخ الامتحان.');
    }

    /** Validates each question by its type and cleans its texts to the exam's languages. */
    public static function checkQuestions(array $questions, array $langs): array
    {
        $errors = [];
        $out = [];
        foreach (array_values($questions) as $i => $q) {
            $n = $i + 1;
            $type = $q['type'];
            // Formatted text (the editor's HTML), cleaned; not for fill-in-the-blanks, whose
            // brackets are read from plain text.
            $format = ($q['format'] ?? 'text') === 'html' && ! in_array($type, Question::BLANK_TYPES, true) ? 'html' : 'text';
            $body = $format === 'html' ? self::cleanHtml($q['body'] ?? [], $langs) : Question::cleanTexts($q['body'] ?? [], $langs);
            if ($type === Question::RANDOM) {
                $settings = (array) ($q['settings'] ?? []);
                $category = \App\Models\Exams\BankCategory::find((int) ($settings['category_id'] ?? 0));
                $count = (int) ($settings['count'] ?? 0);
                if (! $category || ! $category->isUsableBy(auth()->user())) {
                    $errors["questions.$i.settings"] = "السؤال $n: اختر فئة من بنك الأسئلة.";
                }
                $slot = array_filter([
                    'category_id' => (int) ($settings['category_id'] ?? 0), 'count' => $count,
                    'difficulty' => in_array((int) ($settings['difficulty'] ?? 0), [1, 2, 3], true) ? (int) $settings['difficulty'] : null,
                    'tag' => trim((string) ($settings['tag'] ?? '')) ?: null,
                ], fn ($v) => $v !== null);
                $available = $category ? Question::randomPool($slot)->count() : 0;
                if ($category && $category->isUsableBy(auth()->user()) && ($count < 1 || $count > $available)) {
                    $errors["questions.$i.settings"] = "السؤال $n: العدد بين 1 و{$available} (أسئلة الفئة المطابقة).";
                }
                $out[] = ['id' => $q['id'] ?? null, 'type' => $type, 'format' => 'text', 'tags' => [], 'difficulty' => null, 'body' => $body, 'feedback' => [], 'points' => (float) ($q['points'] ?? 1),
                    'settings' => $slot, 'choices' => []];

                continue;
            }
            if ($body === []) {
                $errors["questions.$i.body"] = "السؤال $n: اكتب نص السؤال.";
            }
            $choices = collect($q['choices'] ?? [])->values()->map(fn ($c) => [
                'id' => $c['id'] ?? null,
                'body' => Choice::cleanTexts($c['body'] ?? [], $langs),
                'match' => Choice::cleanTexts($c['match'] ?? [], $langs),
                'is_correct' => (bool) ($c['is_correct'] ?? false),
            ])->filter(fn ($c) => $c['body'] !== [])->values();
            $settings = (array) ($q['settings'] ?? []);

            if (in_array($type, Question::CHOICE_TYPES, true)) {
                if ($choices->count() < 2) {
                    $errors["questions.$i.choices"] = "السؤال $n: يحتاج خيارين على الأقل.";
                }
                $correct = $choices->where('is_correct', true)->count();
                if (in_array($type, [Question::SINGLE, Question::TRUE_FALSE], true) && $correct !== 1) {
                    $errors["questions.$i.choices"] = "السؤال $n: حدّد إجابة صحيحة واحدة.";
                }
                if ($type === Question::MULTIPLE && $correct < 1) {
                    $errors["questions.$i.choices"] = "السؤال $n: حدّد إجابة صحيحة واحدة على الأقل.";
                }
                if ($type === Question::MATCHING && $choices->contains(fn ($c) => $c['match'] === [])) {
                    $errors["questions.$i.choices"] = "السؤال $n: لكل عنصر ما يقابله.";
                }
                if ($type === Question::CLASSIFY) {
                    if ($choices->contains(fn ($c) => $c['match'] === [])) {
                        $errors["questions.$i.choices"] = "السؤال $n: اختر فئة لكل عنصر.";
                    } elseif ($choices->map(fn ($c) => \App\Services\Exams\Grader::normalize(reset($c['match'])))->unique()->count() < 2) {
                        $errors["questions.$i.choices"] = "السؤال $n: يحتاج فئتين على الأقل.";
                    }
                }
            } else {
                $choices = collect();
            }
            if ($type === Question::SHORT) {
                $settings['answers'] = collect($settings['answers'] ?? [])->map(fn ($a) => trim((string) $a))->filter()->values()->all();
                $settings['case_sensitive'] = (bool) ($settings['case_sensitive'] ?? false);
                if ($settings['answers'] === []) {
                    $errors["questions.$i.settings"] = "السؤال $n: اكتب إجابة مقبولة واحدة على الأقل.";
                }
            }
            if ($type === Question::DRAG_TEXT) {
                $settings = ['distractors' => collect($settings['distractors'] ?? [])->map(fn ($w) => trim((string) $w))->filter()->unique()->values()->all()];
            }
            if ($type === Question::DRAG_IMAGE) {
                $image = (string) ($settings['image'] ?? '');
                $zones = collect($settings['zones'] ?? [])->values()->map(fn ($z) => is_array($z) ? [
                    'x' => round(max(0, min(100, (float) ($z['x'] ?? 0))), 2), 'y' => round(max(0, min(100, (float) ($z['y'] ?? 0))), 2),
                    'w' => round(max(0, min(100, (float) ($z['w'] ?? 0))), 2), 'h' => round(max(0, min(100, (float) ($z['h'] ?? 0))), 2),
                ] : null)->all();
                if (! self::imageExists($image)) {
                    $errors["questions.$i.settings"] = "السؤال $n: ارفع الصورة.";
                } elseif ($choices->count() < 1 || collect(range(0, max(0, $choices->count() - 1)))->contains(fn ($k) => empty($zones[$k]) || $zones[$k]['w'] < 1 || $zones[$k]['h'] < 1)) {
                    $errors["questions.$i.settings"] = "السؤال $n: لكل علامة حدّد مكانها على الصورة.";
                }
                $settings = ['image' => $image, 'zones' => array_slice($zones, 0, $choices->count())];
            }
            if ($type === Question::AUDIO) {
                $settings = ['max_seconds' => max(10, min(600, (int) ($settings['max_seconds'] ?? 120)))];
            }
            if ($type === Question::CLOZE || $type === Question::DRAG_TEXT) {
                $counts = collect($body)->map(fn ($t) => count(\App\Services\Exams\Cloze::blanks($t)))->unique();
                if ($body !== [] && $counts->first() === 0) {
                    $errors["questions.$i.body"] = "السؤال $n: ضع فراغاً واحداً على الأقل بين [[ و ]].";
                } elseif ($counts->count() > 1) {
                    $errors["questions.$i.body"] = "السؤال $n: عدد الفراغات يجب أن يكون نفسه في كل لغة.";
                }
            }
            if ($type === Question::NUMERIC) {
                if (\App\Services\Exams\Grader::toNumber($settings['answer'] ?? null) === null) {
                    $errors["questions.$i.settings"] = "السؤال $n: اكتب الإجابة الرقمية.";
                }
                $settings = ['answer' => (string) ($settings['answer'] ?? ''), 'tolerance' => (string) ($settings['tolerance'] ?? '0')];
            }
            if ($type === Question::ESSAY) {
                $settings = array_filter([
                    'min_words' => isset($settings['min_words']) && $settings['min_words'] !== '' ? (int) $settings['min_words'] : null,
                    'max_words' => isset($settings['max_words']) && $settings['max_words'] !== '' ? (int) $settings['max_words'] : null,
                ], fn ($v) => $v !== null);
            }
            if ($type === Question::UPLOAD) {
                $types = collect(preg_split('/[\s,،;]+/u', strtolower((string) ($settings['types'] ?? ''))))
                    ->map(fn ($t) => ltrim($t, '.'))->filter(fn ($t) => preg_match('/^[a-z0-9]{1,8}$/', $t))->unique()->values();
                $settings = ['max_mb' => max(1, min(50, (int) ($settings['max_mb'] ?? 10))), 'types' => $types->implode(',')];
            }
            if ($type === Question::HOTSPOT) {
                $image = (string) ($settings['image'] ?? '');
                $zones = collect($settings['zones'] ?? [])->map(fn ($z) => [
                    'x' => round(max(0, min(100, (float) ($z['x'] ?? 0))), 2), 'y' => round(max(0, min(100, (float) ($z['y'] ?? 0))), 2),
                    'w' => round(max(0, min(100, (float) ($z['w'] ?? 0))), 2), 'h' => round(max(0, min(100, (float) ($z['h'] ?? 0))), 2),
                ])->filter(fn ($z) => $z['w'] >= 1 && $z['h'] >= 1)->values()->all();
                if (! self::imageExists($image)) {
                    $errors["questions.$i.settings"] = "السؤال $n: ارفع الصورة.";
                } elseif ($zones === []) {
                    $errors["questions.$i.settings"] = "السؤال $n: ارسم على الصورة منطقة الإجابة الصحيحة.";
                }
                $settings = ['image' => $image, 'zones' => $zones];
            }
            if ($type === Question::CALCULATED) {
                [$settings, $error] = self::checkCalculated($settings, $body);
                if ($error) {
                    $errors["questions.$i.settings"] = "السؤال $n: $error";
                }
            }
            if (! in_array($type, [Question::SHORT, Question::NUMERIC, Question::ESSAY, Question::UPLOAD, Question::HOTSPOT, Question::CALCULATED, Question::DRAG_TEXT, Question::DRAG_IMAGE, Question::AUDIO], true)) {
                $settings = [];
            }
            // Hand-marked questions may have a rubric: criteria, each worth some of the points.
            if (in_array($type, Question::MANUAL_TYPES, true)) {
                $rubric = collect($q['settings']['rubric'] ?? [])->map(fn ($c) => [
                    'name' => trim(mb_substr((string) ($c['name'] ?? ''), 0, 120)), 'max' => round(max(0, (float) ($c['max'] ?? 0)), 2),
                ])->filter(fn ($c) => $c['name'] !== '' && $c['max'] > 0)->values()->all();
                if ($rubric !== []) {
                    $settings['rubric'] = $rubric;
                    if (abs(array_sum(array_column($rubric, 'max')) - (float) ($q['points'] ?? 1)) > 0.001) {
                        $errors["questions.$i.settings"] = "السؤال $n: مجموع درجات معايير التصحيح يجب أن يساوي درجة السؤال.";
                    }
                }
            }

            $out[] = [
                'id' => $q['id'] ?? null,
                'type' => $type,
                'format' => $format,
                'tags' => collect($q['tags'] ?? [])->map(fn ($t) => trim(mb_substr((string) $t, 0, 50)))->filter()->unique()->values()->all(),
                'difficulty' => in_array((int) ($q['difficulty'] ?? 0), [1, 2, 3], true) ? (int) $q['difficulty'] : null,
                'body' => $body,
                'feedback' => $format === 'html' ? self::cleanHtml($q['feedback'] ?? [], $langs) : Question::cleanTexts($q['feedback'] ?? [], $langs),
                'points' => (float) ($q['points'] ?? 1),
                'settings' => $settings,
                'choices' => $choices->all(),
            ];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** Formatted texts by language, cleaned; one with neither words nor media counts as empty. */
    private static function cleanHtml(array $texts, array $langs): array
    {
        return collect($langs)->mapWithKeys(fn ($l) => [$l => \App\Services\Exams\HtmlCleaner::clean((string) ($texts[$l] ?? ''))])
            ->filter(fn ($html) => trim(\App\Services\Exams\HtmlCleaner::text($html)) !== '' || preg_match('/<(img|audio|video)\b/', $html))
            ->all();
    }

    /** An address (192.168.1.20) or a range (192.168.1.0/24), IPv4 or IPv6. */
    private static function validRange(string $range): bool
    {
        [$ip, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $v4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        if (! $v4 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        return $bits === null || (ctype_digit($bits) && (int) $bits <= ($v4 ? 32 : 128));
    }

    /** A calculated question's settings, cleaned, and what is wrong with them (or null). */
    private static function checkCalculated(array $settings, array $body): array
    {
        $vars = collect($settings['vars'] ?? [])->map(fn ($v) => [
            'name' => strtolower(trim((string) ($v['name'] ?? ''))),
            'min' => \App\Services\Exams\Grader::toNumber($v['min'] ?? null),
            'max' => \App\Services\Exams\Grader::toNumber($v['max'] ?? null),
            'decimals' => max(0, min(6, (int) ($v['decimals'] ?? 0))),
        ])->filter(fn ($v) => $v['name'] !== '')->unique('name')->values();
        $formula = trim((string) ($settings['formula'] ?? ''));
        $tolerance = trim((string) ($settings['tolerance'] ?? '0')) ?: '0';
        $decimals = isset($settings['decimals']) && $settings['decimals'] !== '' && $settings['decimals'] !== null ? max(0, min(6, (int) $settings['decimals'])) : null;
        $clean = ['vars' => $vars->all(), 'formula' => $formula, 'tolerance' => $tolerance, 'decimals' => $decimals];

        if ($vars->isEmpty()) {
            return [$clean, 'أضف متغيراً واحداً على الأقل، واكتبه في نص السؤال بين { }.'];
        }
        if ($bad = $vars->first(fn ($v) => ! preg_match('/^[a-z_][a-z0-9_]*$/', $v['name']) || in_array($v['name'], ['pi', 'e'], true))) {
            return [$clean, "اسم المتغير «{$bad['name']}» يجب أن يكون حروفاً إنكليزية."];
        }
        if ($bad = $vars->first(fn ($v) => $v['min'] === null || $v['max'] === null || $v['min'] > $v['max'])) {
            return [$clean, "حدّد أصغر وأكبر قيمة للمتغير {$bad['name']}."];
        }
        if ($missing = collect($body)->map(fn ($t) => $vars->pluck('name')->reject(fn ($n) => stripos($t, '{'.$n.'}') !== false)->first())->filter()->first()) {
            return [$clean, "المتغير {{$missing}} غير موجود في نص السؤال."];
        }
        if ($unknown = array_diff(\App\Services\Exams\Formula::variables($formula), $vars->pluck('name')->all())) {
            return [$clean, 'في المعادلة أسماء غير معرّفة: '.implode('، ', $unknown).'.'];
        }
        if (! preg_match('/^\d+(\.\d+)?%?$/', $tolerance)) {
            return [$clean, 'هامش الخطأ رقم (0.1) أو نسبة (2%).'];
        }
        // The formula must work across the ranges: at the smallest, the largest and the middle values.
        foreach (['min', 'max', 'mid'] as $at) {
            $values = $vars->mapWithKeys(fn ($v) => [$v['name'] => $at === 'mid' ? ($v['min'] + $v['max']) / 2 : $v[$at]])->all();
            try {
                \App\Services\Exams\Formula::evaluate($formula, $values);
            } catch (\InvalidArgumentException $e) {
                return [$clean, ($formula === '' ? 'اكتب المعادلة.' : 'المعادلة: '.$e->getMessage())];
            }
        }

        return [$clean, null];
    }

    /** The exam on paper: questions with space to answer, or the answer key (?key=1). */
    public function print(Request $request, Exam $exam): Response
    {
        // The reviewer reads the questions (with the answers) here.
        abort_unless($exam->isManageableBy($request->user()) || $exam->reviewer_id === $request->user()->id, 404);
        $key = $request->boolean('key');
        // Versions A/B/C: each printed in the order its computers show it.
        $variant = $exam->variants > 1 ? max(1, min($exam->variants, $request->integer('variant', 1))) : null;
        $questions = $exam->questions()->with('choices')->get();
        if ($variant && ($variant > 1 || $exam->shuffle_questions)) {
            $questions = $questions->sortBy(fn (Question $q) => crc32('v'.$exam->id.'-'.$variant.'-q-'.$q->id))->values();
        }

        return Inertia::render('Exams/Manage/Print', [
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->text('title'),
                'description' => $exam->text('description'),
                'subject' => $exam->subject,
                'stage' => $exam->stage,
                'department' => $exam->department,
                'duration' => $exam->duration_minutes,
                'max_score' => $exam->maxScore(),
                'dir' => $this->presenter->dir($exam),
                'lang' => $exam->languages[0] ?? 'ar',
                'variants' => $exam->variants > 1 ? $exam->variants : 1,
                'variant' => $variant,
            ],
            'answerKey' => $key,
            'site' => config('app.name'),
            'questions' => $questions->map(fn (Question $q) => $this->presenter->forPrint($q, $exam, $key, $variant))->values(),
        ]);
    }

    /** A picture for a question (hotspot): kept privately, named by its content. */
    public function image(Request $request): JsonResponse
    {
        // Pictures for hotspot questions and formatted text; sound and video for formatted text.
        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp,mp3,ogg,oga,wav,m4a,mp4,webm', 'max:51200']]);
        $file = $request->file('image');
        if (in_array(strtolower($file->extension()), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) && $file->getSize() > 8 * 1024 * 1024) {
            throw ValidationException::withMessages(['image' => 'الصورة أكبر من 8 ميغابايت.']);
        }
        $name = hash_file('sha256', $file->getRealPath()).'.'.strtolower($file->extension());
        if (! Storage::disk('local')->exists(self::IMAGES.'/'.$name)) {
            Storage::disk('local')->putFileAs(self::IMAGES, $file, $name);
        }

        return response()->json(['image' => $name, 'url' => \App\Services\Exams\Presenter::imageUrl($name)]);
    }

    public const IMAGES = 'exam-images';

    public static function imageExists(string $name): bool
    {
        return preg_match('/^[a-f0-9]{64}\.(jpg|jpeg|png|gif|webp|mp3|ogg|oga|wav|m4a|mp4|webm)$/', $name) === 1 && Storage::disk('local')->exists(self::IMAGES.'/'.$name);
    }

    private function authorizeExam(Request $request, Exam $exam): void
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
    }
}
