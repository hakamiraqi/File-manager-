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
            'questions' => $current ? $current->questions()->with('choices')->get()->map(fn (Question $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'points' => $q->points,
                'body' => Question::pick($q->body, app()->getLocale()),
                'languages' => array_keys((array) $q->body),
            ]) : [],
        ]);
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
        $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:xlsx,csv,txt']],
            ['file.extensions' => 'الملف يجب أن يكون Excel (.xlsx) أو CSV أو نص Aiken (.txt).']);
        $lang = in_array($request->input('lang'), Exam::LANGUAGES, true) ? $request->input('lang') : 'ar';
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $result = $ext === 'txt'
            ? $importer->fromAiken((string) file_get_contents($file->getRealPath()), $lang)
            : $importer->fromRows($reader->read($file->getRealPath(), $ext), $lang);

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
