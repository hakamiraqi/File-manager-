<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Answer;
use App\Models\Exams\BankCategory;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Services\Exams\Presenter;
use App\Services\Exams\QuestionImporter;
use App\Services\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** بنك الأسئلة: categories of questions kept outside exams, copied into exams or drawn at random. */
class BankController extends Controller
{
    public function index(Request $request, Presenter $presenter): Response
    {
        $user = $request->user();
        $categories = BankCategory::usableBy($user)->withCount('questions')->with('owner:id,name')->orderBy('name')->get();
        $current = $categories->firstWhere('id', $request->integer('category')) ?? $categories->first();

        return Inertia::render('Exams/Manage/Bank', [
            'categories' => $categories->map(fn (BankCategory $c) => [
                'id' => $c->id, 'name' => $c->name, 'subject' => $c->subject, 'is_shared' => $c->is_shared,
                'questions' => $c->questions_count, 'owner' => $c->owner?->name, 'editable' => $c->isEditableBy($user),
            ]),
            'current' => $current?->id,
            'ai' => \App\Services\Exams\AiMarker::enabled(),
            'questions' => $current ? ($qs = $current->questions()->with('choices')->get())->map(fn (Question $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'points' => $q->points,
                'body' => $q->plainBody([]),
                'languages' => array_keys((array) $q->body),
                'quality' => ($quality ??= self::quality($qs))[$q->id] ?? null,
            ]) : [],
        ]);
    }

    /**
     * How the bank's questions did in real exams (drawn by random slots): how often they were
     * used (a question seen by many is no secret any more), how easy, and whether the stronger
     * students got them more often than the weaker (discrimination).
     */
    public static function quality(\Illuminate\Support\Collection $questions): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('exam_answers')->join('exam_attempts', 'exam_attempts.id', '=', 'exam_answers.exam_attempt_id')
            ->whereIn('exam_answers.exam_question_id', $questions->pluck('id'))->whereNotNull('exam_attempts.submitted_at')->whereNotNull('exam_answers.points')
            ->get(['exam_answers.exam_question_id as q', 'exam_answers.points as p', 'exam_attempts.score as s', 'exam_attempts.max_score as m'])->groupBy('q');
        $out = [];
        foreach ($questions as $q) {
            $list = $rows[$q->id] ?? collect();
            $n = $list->count();
            if ($n === 0 || $q->points <= 0) {
                $out[$q->id] = ['uses' => 0, 'facility' => null, 'discrimination' => null, 'flags' => []];

                continue;
            }
            $x = $list->map(fn ($r) => min(1, max(0, $r->p / $q->points)))->values();
            $y = $list->map(fn ($r) => $r->m > 0 ? $r->s / $r->m : 0)->values();
            $facility = round($x->avg(), 2);
            $disc = null;
            if ($n >= 10) {
                [$mx, $my] = [$x->avg(), $y->avg()];
                $cov = $x->keys()->sum(fn ($i) => ($x[$i] - $mx) * ($y[$i] - $my));
                $sx = sqrt($x->sum(fn ($v) => ($v - $mx) ** 2));
                $sy = sqrt($y->sum(fn ($v) => ($v - $my) ** 2));
                $disc = $sx > 0 && $sy > 0 ? round($cov / ($sx * $sy), 2) : null;
            }
            $flags = array_values(array_filter([
                $n >= 100 ? 'exposed' : null,
                $n >= 10 && $facility >= 0.9 ? 'too_easy' : null,
                $n >= 10 && $facility <= 0.2 ? 'too_hard' : null,
                $disc !== null && $disc < 0.15 ? 'weak' : null,
                $disc !== null && $disc < 0 ? 'check_key' : null,
            ]));
            $out[$q->id] = ['uses' => $n, 'facility' => $facility, 'discrimination' => $disc, 'flags' => $flags];
        }

        return $out;
    }

    /**
     * The blueprint's raw material: how many questions a category has for each tag (chapter,
     * objective, Bloom level…) and each difficulty, so a teacher can draw an exam from it.
     */
    public function matrix(Request $request, BankCategory $category): \Illuminate\Http\JsonResponse
    {
        abort_unless(BankCategory::usableBy($request->user())->whereKey($category->id)->exists(), 404);
        $qs = $category->questions()->whereNull('exam_id')->where('type', '!=', Question::RANDOM)->get(['id', 'tags', 'difficulty']);
        $tags = $qs->flatMap(fn ($q) => $q->tags ?? [])->unique()->sort()->values();
        $count = fn ($list) => ['0' => $list->count()] + collect([1, 2, 3])->mapWithKeys(fn ($d) => [(string) $d => $list->where('difficulty', $d)->count()])->all();

        return response()->json([
            'total' => $count($qs),
            'tags' => $tags->map(fn ($t) => ['tag' => $t, 'counts' => $count($qs->filter(fn ($q) => in_array($t, $q->tags ?? [], true)))])->values(),
        ]);
    }

    /** Draft questions from a lecture, by AI (hosted site), for the teacher to review. */
    public function generate(Request $request, BankCategory $category, \App\Services\Exams\AiQuestions $ai): \Illuminate\Http\JsonResponse
    {
        abort_unless($category->isEditableBy($request->user()) && \App\Services\Exams\AiMarker::enabled(), 404);
        $data = $request->validate(['file' => ['nullable', 'file', 'max:30720', 'extensions:pdf,docx,txt'], 'text' => ['nullable', 'string', 'max:200000'],
            'count' => ['required', 'integer', 'min:1', 'max:40'], 'types' => ['required', 'array'], 'types.*' => ['in:single,true_false,short'], 'lang' => ['nullable', 'in:ar,en']]);
        $file = $request->file('file');
        $ext = $file ? strtolower($file->getClientOriginalExtension()) : null;
        $text = (string) ($data['text'] ?? '');
        if ($ext === 'docx') {
            $text .= "\n".QuestionImporter::docxText($file->getRealPath());
        } elseif ($ext === 'txt') {
            $text .= "\n".file_get_contents($file->getRealPath());
        }
        if ($ext !== 'pdf' && trim($text) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['file' => 'أرفق ملف المحاضرة أو الصق نصها.']);
        }
        try {
            $questions = $ai->generate($ext === 'pdf' ? $file->getRealPath() : null, $text, $data['count'], $data['types'], $data['lang'] ?? 'ar');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['questions' => $questions]);
    }

    /** The drafts the teacher kept, into the category. */
    public function saveGenerated(Request $request, BankCategory $category): RedirectResponse
    {
        abort_unless($category->isEditableBy($request->user()), 404);
        $data = $request->validate(['lang' => ['nullable', 'in:ar,en'], 'questions' => ['required', 'array', 'max:40'],
            'questions.*.type' => ['required', 'in:single,true_false,short'], 'questions.*.body' => ['required', 'string', 'max:3000'],
            'questions.*.choices' => ['array'], 'questions.*.choices.*.text' => ['required', 'string', 'max:500'], 'questions.*.choices.*.correct' => ['boolean'],
            'questions.*.answer' => ['nullable', 'string', 'max:300'], 'questions.*.difficulty' => ['nullable', 'integer', 'min:1', 'max:3'], 'questions.*.tag' => ['nullable', 'string', 'max:100']]);
        $lang = $data['lang'] ?? 'ar';
        DB::transaction(function () use ($data, $category, $lang) {
            foreach ($data['questions'] as $q) {
                $question = Question::create(['bank_category_id' => $category->id, 'type' => $q['type'], 'body' => [$lang => $q['body']], 'points' => 1,
                    'difficulty' => $q['difficulty'] ?? null, 'tags' => filled($q['tag'] ?? null) ? [$q['tag']] : null,
                    'settings' => $q['type'] === 'short' ? ['answers' => array_values(array_filter([trim((string) ($q['answer'] ?? ''))]))] : null]);
                $choices = $q['type'] === 'true_false'
                    ? [['text' => $lang === 'en' ? 'True' : 'صح', 'correct' => in_array(mb_strtolower((string) ($q['answer'] ?? '')), ['true', 'صح', 'صحيح'], true)],
                        ['text' => $lang === 'en' ? 'False' : 'خطأ', 'correct' => ! in_array(mb_strtolower((string) ($q['answer'] ?? '')), ['true', 'صح', 'صحيح'], true)]]
                    : ($q['choices'] ?? []);
                foreach (array_values($choices) as $j => $c) {
                    \App\Models\Exams\Choice::create(['exam_question_id' => $question->id, 'body' => [$lang => $c['text']], 'is_correct' => (bool) ($c['correct'] ?? false), 'position' => $j]);
                }
            }
        });
        ActivityLog::record('exam.ai_questions', 'أضاف '.count($data['questions']).' سؤالاً مولّداً إلى «'.$category->name.'»');

        return back()->with('success', 'أُضيف '.count($data['questions']).' سؤالاً إلى البنك.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'subject' => ['nullable', 'string', 'max:255'], 'is_shared' => ['boolean']]);
        $category = BankCategory::create($data + ['user_id' => $request->user()->id]);

        return redirect()->route('exams.manage.bank', ['category' => $category->id])->with('success', 'تمت إضافة الفئة.');
    }

    public function update(Request $request, BankCategory $category): RedirectResponse
    {
        abort_unless($category->isEditableBy($request->user()), 404);
        $category->update($request->validate(['name' => ['required', 'string', 'max:255'], 'subject' => ['nullable', 'string', 'max:255'], 'is_shared' => ['boolean']]));

        return back()->with('success', 'تم حفظ الفئة.');
    }

    public function destroy(Request $request, BankCategory $category): RedirectResponse
    {
        abort_unless($category->isEditableBy($request->user()), 404);
        if (Question::where('type', Question::RANDOM)->whereNotNull('exam_id')->get()->contains(fn ($q) => (int) ($q->settings['category_id'] ?? 0) === $category->id)) {
            throw ValidationException::withMessages(['category' => 'امتحان يسحب أسئلة عشوائية من هذه الفئة؛ احذف ذلك السؤال منه أولاً.']);
        }
        if (Answer::whereIn('exam_question_id', $category->questions()->select('id'))->exists()) {
            throw ValidationException::withMessages(['category' => 'طلاب أجابوا عن أسئلة من هذه الفئة؛ حذفها يمحو إجاباتهم.']);
        }
        $category->delete();

        return redirect()->route('exams.manage.bank')->with('success', 'تم حذف الفئة وأسئلتها.');
    }

    public function destroyQuestion(Request $request, Question $question): RedirectResponse
    {
        abort_unless($question->exam_id === null && $question->category?->isEditableBy($request->user()), 404);
        if (Answer::where('exam_question_id', $question->id)->exists()) {
            throw ValidationException::withMessages(['question' => 'سُحب هذا السؤال في امتحان وأجاب عنه طلاب؛ لا يمكن حذفه.']);
        }
        $question->delete();

        return back()->with('success', 'تم حذف السؤال من البنك.');
    }

    /** Copies one of an exam's questions into a bank category. */
    public function fromExam(Request $request, Question $question): RedirectResponse
    {
        abort_unless($question->exam && $question->exam->isManageableBy($request->user()) && $question->type !== Question::RANDOM, 404);
        $category = BankCategory::findOrFail($request->integer('category_id'));
        abort_unless($category->isEditableBy($request->user()), 404);
        $question->load('choices')->copyTo(null, $category->id);

        return back()->with('success', "نُسخ السؤال إلى «{$category->name}».");
    }

    /** Copies chosen bank questions into an exam, at its end. */
    public function toExam(Request $request, Exam $exam): RedirectResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        $ids = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer']])['ids'];
        $questions = Question::with(['choices', 'category'])->whereIn('id', $ids)->whereNull('exam_id')->get()
            ->filter(fn (Question $q) => $q->category?->isUsableBy($request->user()));

        DB::transaction(function () use ($exam, $questions) {
            $position = (int) $exam->questions()->max('position') + 1;
            foreach ($questions as $q) {
                $q->copyTo($exam->id, null, $position++);
            }
            // Texts in a language the exam lacks stay stored; add the language to show them.
        });

        return back()->with('success', 'أُضيف '.$questions->count().' سؤالاً من البنك.');
    }

    public function import(Request $request, BankCategory $category, SpreadsheetReader $reader, QuestionImporter $importer): RedirectResponse
    {
        abort_unless($category->isEditableBy($request->user()), 404);
        $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:'.implode(',', QuestionImporter::EXTENSIONS)]],
            ['file.extensions' => 'الملف يجب أن يكون Excel (.xlsx) أو CSV أو نص Aiken/GIFT (.txt، .gift، أو Word .docx) أو Moodle XML (.xml).']);
        $lang = in_array($request->input('lang'), Exam::LANGUAGES, true) ? $request->input('lang') : 'ar';
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $result = $importer->fromFile($file->getRealPath(), $ext, $lang, $reader);

        $added = 0;
        $skipped = $result['skipped'];
        foreach ($result['questions'] as $line => $q) {
            if ($q['type'] === Question::RANDOM) {
                continue;
            }
            try {
                $q = ManageController::checkQuestions([$q], [$lang])[0];
            } catch (ValidationException $e) {
                $skipped[$line] = collect($e->errors())->flatten()->first();

                continue;
            }
            $question = Question::create(['exam_id' => null, 'bank_category_id' => $category->id, 'type' => $q['type'], 'body' => $q['body'],
                'feedback' => $q['feedback'] ?: null, 'points' => $q['points'], 'settings' => $q['settings'] ?: null]);
            foreach ($q['choices'] as $j => $c) {
                Choice::create(['exam_question_id' => $question->id, 'body' => $c['body'], 'match' => $c['match'] ?: null, 'is_correct' => $c['is_correct'], 'position' => $j]);
            }
            $added++;
        }
        ActivityLog::record('exam.imported', "استورد {$added} سؤالاً إلى بنك «{$category->name}»");
        if ($added === 0) {
            return back()->withErrors(['file' => 'لم يُضف شيء. '.collect($skipped)->take(5)->map(fn ($w, $l) => "سطر $l: $w")->implode('؛ ')]);
        }

        return back()->with('success', "أُضيف {$added} سؤالاً.".($skipped ? ' تخطّيت '.count($skipped).'.' : ''));
    }
}
