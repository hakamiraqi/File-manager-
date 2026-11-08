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
            'exams' => $exams->map(fn (Exam $e) => [
                'id' => $e->id,
                'title' => $e->text('title'),
                'subject' => $e->subject,
                'stage' => $e->stage,
                'author' => $e->author?->name,
                'can_edit' => $e->isManageableBy($user),
                'is_published' => $e->is_published,
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
            'attempts' => $exam->attempts()->count(),
            'languages' => Exam::LANGUAGES,
            'types' => Question::TYPES,
            'categories' => \App\Models\Exams\BankCategory::usableBy($request->user())->withCount('questions')->orderBy('name')->get(['id', 'name', 'subject', 'user_id', 'is_shared']),
        ]);
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
            'marking_tolerance' => ['nullable', 'integer', 'min:0', 'max:100'],
            'questions' => ['array', 'max:500'],
            'questions.*.id' => ['nullable', 'integer'],
            'questions.*.type' => ['required', Rule::in(Question::TYPES)],
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

        DB::transaction(function () use ($exam, $data, $langs, $title, $questions) {
            $exam->update([
                'title' => $title,
                'description' => Exam::cleanTexts($data['description'] ?? [], $langs) ?: null,
                'languages' => $langs,
            ] + collect($data)->only([
                'subject', 'stage', 'department', 'direction', 'duration_minutes', 'opens_at', 'closes_at', 'max_attempts',
                'grading_method', 'pass_mark', 'password', 'shuffle_questions', 'shuffle_choices', 'show_results', 'show_answers', 'is_published',
                'is_practice', 'seb_required', 'seb_keys', 'double_marking',
            ])->all() + (isset($data['marking_tolerance']) ? ['marking_tolerance' => $data['marking_tolerance']] : []));

            $existing = $exam->questions()->with('choices')->get()->keyBy('id');
            $keep = [];
            foreach ($questions as $i => $q) {
                $question = isset($q['id'], $existing[$q['id']]) ? $existing[$q['id']] : new Question(['exam_id' => $exam->id]);
                $question->fill([
                    'type' => $q['type'], 'body' => $q['body'], 'feedback' => $q['feedback'] ?: null,
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
        });

        ActivityLog::record('exam.updated', "حدّث الامتحان \"{$exam->text('title')}\"", $exam);

        return back()->with('success', 'تم حفظ الامتحان.');
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
            $body = Question::cleanTexts($q['body'] ?? [], $langs);
            if ($type === Question::RANDOM) {
                $settings = (array) ($q['settings'] ?? []);
                $category = \App\Models\Exams\BankCategory::find((int) ($settings['category_id'] ?? 0));
                $count = (int) ($settings['count'] ?? 0);
                if (! $category || ! $category->isUsableBy(auth()->user())) {
                    $errors["questions.$i.settings"] = "السؤال $n: اختر فئة من بنك الأسئلة.";
                } elseif ($count < 1 || $count > $category->questions()->count()) {
                    $errors["questions.$i.settings"] = "السؤال $n: العدد بين 1 و{$category->questions()->count()} (أسئلة الفئة).";
                }
                $out[] = ['id' => $q['id'] ?? null, 'type' => $type, 'body' => $body, 'feedback' => [], 'points' => (float) ($q['points'] ?? 1),
                    'settings' => ['category_id' => (int) ($settings['category_id'] ?? 0), 'count' => $count], 'choices' => []];

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
            if ($type === Question::CLOZE) {
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
            if (! in_array($type, [Question::SHORT, Question::NUMERIC, Question::ESSAY, Question::UPLOAD, Question::HOTSPOT, Question::CALCULATED], true)) {
                $settings = [];
            }

            $out[] = [
                'id' => $q['id'] ?? null,
                'type' => $type,
                'body' => $body,
                'feedback' => Question::cleanTexts($q['feedback'] ?? [], $langs),
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
        $this->authorizeExam($request, $exam);
        $key = $request->boolean('key');

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
            ],
            'answerKey' => $key,
            'site' => config('app.name'),
            'questions' => $exam->questions()->with('choices')->get()->map(fn (Question $q) => $this->presenter->forPrint($q, $exam, $key))->values(),
        ]);
    }

    /** A picture for a question (hotspot): kept privately, named by its content. */
    public function image(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:8192']]);
        $file = $request->file('image');
        $name = hash_file('sha256', $file->getRealPath()).'.'.strtolower($file->extension());
        if (! Storage::disk('local')->exists(self::IMAGES.'/'.$name)) {
            Storage::disk('local')->putFileAs(self::IMAGES, $file, $name);
        }

        return response()->json(['image' => $name, 'url' => \App\Services\Exams\Presenter::imageUrl($name)]);
    }

    public const IMAGES = 'exam-images';

    public static function imageExists(string $name): bool
    {
        return preg_match('/^[a-f0-9]{64}\.(jpg|jpeg|png|gif|webp)$/', $name) === 1 && Storage::disk('local')->exists(self::IMAGES.'/'.$name);
    }

    private function authorizeExam(Request $request, Exam $exam): void
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
    }
}
