<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Services\Exams\Presenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $exams = Exam::manageableBy($user)->withCount(['questions', 'attempts'])
            ->withCount(['attempts as pending_count' => fn ($q) => $q->where('needs_grading', true)])
            ->with('author:id,name')->latest('id')->get();

        return Inertia::render('Exams/Manage/Index', [
            'exams' => $exams->map(fn (Exam $e) => [
                'id' => $e->id,
                'title' => $e->text('title'),
                'subject' => $e->subject,
                'stage' => $e->stage,
                'author' => $e->author?->name,
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
            ])->all());

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
            if (! in_array($type, [Question::SHORT, Question::NUMERIC, Question::ESSAY], true)) {
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

    private function authorizeExam(Request $request, Exam $exam): void
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
    }
}
