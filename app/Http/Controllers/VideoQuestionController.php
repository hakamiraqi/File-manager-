<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Setting;
use App\Models\VideoQuestion;
use App\Models\VideoQuestionAnswer;
use App\Models\VideoQuestionChoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The interactive-video quiz layer (questions/choices/answers) — a global
 * "الفيديو التفاعلي" toggle in Settings gates all of this off entirely
 * regardless of who's asking, on top of the normal per-file 'view' policy
 * and the 'manage_questions' capability for authoring.
 */
class VideoQuestionController extends Controller
{
    /**
     * Questions for a video, plus the current user's own answers so the
     * player can skip re-showing a question already answered. Choices
     * never carry is_correct here — only manage_questions holders get
     * that (needed to render the "correct" radio when editing).
     */
    public function index(Request $request, File $file): JsonResponse
    {
        $this->authorize('view', $file);
        $this->assertEnabled();

        $canManage = $request->user()->canPerform('manage_questions');

        $questions = $file->questions()
            ->with(['choices' => fn ($q) => $q->orderBy('order')])
            ->orderBy('timestamp_seconds')
            ->orderBy('order')
            ->get();

        $answers = VideoQuestionAnswer::whereIn('video_question_id', $questions->pluck('id'))
            ->where('user_id', $request->user()->id)
            ->get()
            ->keyBy('video_question_id');

        $total = $questions->count();
        $correct = $answers->where('is_correct', true)->count();

        return response()->json([
            'questions' => $questions->map(function (VideoQuestion $question) use ($answers, $canManage) {
                $answer = $answers->get($question->id);

                return [
                    'id' => $question->id,
                    'timestamp_seconds' => $question->timestamp_seconds,
                    'question' => $question->question,
                    'choices' => $question->choices->map(fn (VideoQuestionChoice $choice) => [
                        'id' => $choice->id,
                        'label' => $choice->label,
                        'is_correct' => $canManage ? $choice->is_correct : null,
                    ]),
                    'answered_choice_id' => $answer?->video_question_choice_id,
                    'answered_correctly' => $answer?->is_correct,
                ];
            }),
            'score' => ['answered' => $answers->count(), 'correct' => $correct, 'total' => $total],
        ]);
    }

    public function store(Request $request, File $file): RedirectResponse
    {
        $this->authorize('update', $file);
        $this->assertEnabled();
        $this->assertCanManage($request);

        $data = $this->validateQuestion($request);

        DB::transaction(function () use ($data, $file) {
            $question = VideoQuestion::create([
                'file_id' => $file->id,
                'timestamp_seconds' => $data['timestamp_seconds'],
                'question' => $data['question'],
                'order' => $file->questions()->count(),
            ]);

            foreach ($data['choices'] as $index => $choiceLabel) {
                VideoQuestionChoice::create([
                    'video_question_id' => $question->id,
                    'label' => $choiceLabel,
                    'is_correct' => $index === (int) $data['correct_index'],
                    'order' => $index,
                ]);
            }
        });

        ActivityLog::record('video_question.created', "أضاف سؤالاً تفاعلياً للفيديو \"{$file->name}\"", $file);

        return back()->with('success', 'تمت إضافة السؤال.');
    }

    /**
     * Bulk-create questions from an uploaded JSON or CSV file. Rows that
     * fail validation are skipped (and reported back) rather than aborting
     * the whole import, so one bad row doesn't cost the rest of a large
     * batch.
     */
    public function import(Request $request, File $file): RedirectResponse
    {
        $this->authorize('update', $file);
        $this->assertEnabled();
        $this->assertCanManage($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:json,csv,txt', 'max:2048'],
        ]);

        $rows = $this->parseImportFile($request->file('file'));

        if (empty($rows)) {
            throw ValidationException::withMessages(['file' => 'الملف لا يحتوي على أي أسئلة صالحة.']);
        }

        $errors = [];
        $created = 0;

        DB::transaction(function () use ($rows, $file, &$errors, &$created) {
            $order = $file->questions()->count();

            foreach ($rows as $i => $row) {
                $validator = Validator::make($row, [
                    'timestamp_seconds' => ['required', 'integer', 'min:0'],
                    'question' => ['required', 'string', 'max:2000'],
                    'choices' => ['required', 'array', 'min:2', 'max:6'],
                    'choices.*' => ['required', 'string', 'max:255'],
                    'correct_index' => ['required', 'integer', 'min:0'],
                ]);

                if ($validator->fails()) {
                    $errors[] = 'السطر '.($i + 1).': '.$validator->errors()->first();

                    continue;
                }

                if ((int) $row['correct_index'] >= count($row['choices'])) {
                    $errors[] = 'السطر '.($i + 1).': رقم الإجابة الصحيحة غير صالح.';

                    continue;
                }

                $question = VideoQuestion::create([
                    'file_id' => $file->id,
                    'timestamp_seconds' => $row['timestamp_seconds'],
                    'question' => $row['question'],
                    'order' => $order++,
                ]);

                foreach ($row['choices'] as $index => $label) {
                    VideoQuestionChoice::create([
                        'video_question_id' => $question->id,
                        'label' => $label,
                        'is_correct' => $index === (int) $row['correct_index'],
                        'order' => $index,
                    ]);
                }

                $created++;
            }
        });

        if ($created === 0) {
            throw ValidationException::withMessages([
                'file' => 'فشل استيراد كل الأسئلة: '.implode(' | ', array_slice($errors, 0, 5)),
            ]);
        }

        ActivityLog::record('video_question.imported', "استورد {$created} سؤالاً تفاعلياً للفيديو \"{$file->name}\"", $file);

        $message = "تم استيراد {$created} سؤالاً بنجاح.";
        if (! empty($errors)) {
            $message .= ' تم تجاوز '.count($errors).' سطر بسبب أخطاء: '.implode(' | ', array_slice($errors, 0, 5));
        }

        return back()->with('success', $message);
    }

    /**
     * Aggregate results across every respondent for a video's quiz —
     * per-question correct rates (with the per-choice breakdown a manager
     * needs to spot a confusing distractor) and a per-user score table.
     * Restricted the same as authoring: only the file's owner/admin with
     * manage_questions can see how others answered.
     */
    public function report(Request $request, File $file): JsonResponse
    {
        $this->authorize('update', $file);
        $this->assertEnabled();
        $this->assertCanManage($request);

        $questions = $file->questions()
            ->with(['choices' => fn ($q) => $q->orderBy('order')])
            ->orderBy('timestamp_seconds')
            ->orderBy('order')
            ->get();

        $answers = VideoQuestionAnswer::whereIn('video_question_id', $questions->pluck('id'))
            ->with('user:id,name,email')
            ->get();

        $answersByQuestion = $answers->groupBy('video_question_id');
        $answersByUser = $answers->groupBy('user_id');

        $questionReports = $questions->map(function (VideoQuestion $question) use ($answersByQuestion) {
            $questionAnswers = $answersByQuestion->get($question->id, collect());
            $totalAnswers = $questionAnswers->count();

            return [
                'id' => $question->id,
                'timestamp_seconds' => $question->timestamp_seconds,
                'question' => $question->question,
                'total_answers' => $totalAnswers,
                'correct_count' => $questionAnswers->where('is_correct', true)->count(),
                'correct_rate' => $totalAnswers > 0
                    ? round($questionAnswers->where('is_correct', true)->count() / $totalAnswers, 4)
                    : null,
                'choices' => $question->choices->map(fn (VideoQuestionChoice $choice) => [
                    'id' => $choice->id,
                    'label' => $choice->label,
                    'is_correct' => $choice->is_correct,
                    'count' => $questionAnswers->where('video_question_choice_id', $choice->id)->count(),
                    'percent' => $totalAnswers > 0
                        ? round($questionAnswers->where('video_question_choice_id', $choice->id)->count() / $totalAnswers, 4)
                        : null,
                ]),
            ];
        });

        $totalQuestions = $questions->count();

        $userReports = $answersByUser->map(function ($userAnswers, $userId) use ($totalQuestions) {
            $user = $userAnswers->first()->user;
            $correct = $userAnswers->where('is_correct', true)->count();
            $answered = $userAnswers->count();

            return [
                'user_id' => $userId,
                'name' => $user?->name,
                'email' => $user?->email,
                'answered' => $answered,
                'correct' => $correct,
                'total_questions' => $totalQuestions,
                'percent' => $totalQuestions > 0 ? round($correct / $totalQuestions, 4) : null,
            ];
        })->sortByDesc('percent')->values();

        return response()->json([
            'summary' => [
                'total_questions' => $totalQuestions,
                'respondents' => $userReports->count(),
                'average_percent' => $userReports->count() > 0
                    ? round($userReports->avg('percent'), 4)
                    : null,
            ],
            'questions' => $questionReports,
            'users' => $userReports,
        ]);
    }

    /**
     * A sample CSV a question-author can fill in and re-upload via import().
     */
    public function template(Request $request): Response
    {
        $this->assertCanManage($request);

        $csv = "timestamp_seconds,question,choice_1,choice_2,choice_3,choice_4,correct_index\n"
            ."30,\"ما عاصمة العراق؟\",بغداد,القاهرة,الرياض,,0\n"
            ."90,\"ما نتيجة 2 + 2؟\",3,4,5,6,1\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="video-questions-template.csv"',
        ]);
    }

    public function update(Request $request, VideoQuestion $question): RedirectResponse
    {
        $file = $question->file;
        $this->authorize('update', $file);
        $this->assertEnabled();
        $this->assertCanManage($request);

        $data = $this->validateQuestion($request);

        DB::transaction(function () use ($data, $question) {
            $question->update([
                'timestamp_seconds' => $data['timestamp_seconds'],
                'question' => $data['question'],
            ]);

            $question->choices()->delete();
            foreach ($data['choices'] as $index => $choiceLabel) {
                VideoQuestionChoice::create([
                    'video_question_id' => $question->id,
                    'label' => $choiceLabel,
                    'is_correct' => $index === (int) $data['correct_index'],
                    'order' => $index,
                ]);
            }
        });

        ActivityLog::record('video_question.updated', "حدّث سؤالاً تفاعلياً في الفيديو \"{$file->name}\"", $file);

        return back()->with('success', 'تم تحديث السؤال.');
    }

    public function destroy(Request $request, VideoQuestion $question): RedirectResponse
    {
        $file = $question->file;
        $this->authorize('update', $file);
        $this->assertCanManage($request);

        $question->delete();

        ActivityLog::record('video_question.deleted', "حذف سؤالاً تفاعلياً من الفيديو \"{$file->name}\"", $file);

        return back()->with('success', 'تم حذف السؤال.');
    }

    /**
     * One answer per (question, user), enforced by a unique index — a
     * second submission is rejected rather than silently overwriting the
     * first, matching the "answer is final" decision in the design study.
     */
    public function answer(Request $request, VideoQuestion $question): JsonResponse
    {
        $file = $question->file;
        $this->authorize('view', $file);
        $this->assertEnabled();

        $data = $request->validate([
            'choice_id' => ['required', 'integer', 'exists:video_question_choices,id'],
        ]);

        $choice = VideoQuestionChoice::where('video_question_id', $question->id)->findOrFail($data['choice_id']);

        if (VideoQuestionAnswer::where('video_question_id', $question->id)->where('user_id', $request->user()->id)->exists()) {
            throw ValidationException::withMessages(['choice_id' => 'لقد أجبت على هذا السؤال من قبل.']);
        }

        $answer = VideoQuestionAnswer::create([
            'video_question_id' => $question->id,
            'user_id' => $request->user()->id,
            'video_question_choice_id' => $choice->id,
            'is_correct' => $choice->is_correct,
        ]);

        $correctChoiceId = $question->choices()->where('is_correct', true)->value('id');

        $total = $file->questions()->count();
        $answered = VideoQuestionAnswer::whereIn('video_question_id', $file->questions()->pluck('id'))
            ->where('user_id', $request->user()->id)
            ->get();

        return response()->json([
            'is_correct' => $answer->is_correct,
            'correct_choice_id' => $correctChoiceId,
            'score' => ['answered' => $answered->count(), 'correct' => $answered->where('is_correct', true)->count(), 'total' => $total],
        ]);
    }

    private function validateQuestion(Request $request): array
    {
        $data = $request->validate([
            'timestamp_seconds' => ['required', 'integer', 'min:0'],
            'question' => ['required', 'string', 'max:2000'],
            'choices' => ['required', 'array', 'min:2', 'max:6'],
            'choices.*' => ['required', 'string', 'max:255'],
            'correct_index' => ['required', 'integer', 'min:0'],
        ]);

        if ($data['correct_index'] >= count($data['choices'])) {
            throw ValidationException::withMessages(['correct_index' => 'الإجابة الصحيحة يجب أن تشير إلى أحد الخيارات المُدخَلة.']);
        }

        return $data;
    }

    /**
     * Normalizes either a JSON array of question objects or a CSV
     * (timestamp_seconds,question,choice_1..choice_N,correct_index) into a
     * flat list of raw rows for validateQuestion-style checking.
     */
    private function parseImportFile(UploadedFile $upload): array
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        $contents = (string) file_get_contents($upload->getRealPath());

        $looksLikeJson = $extension === 'json' || str_starts_with(trim($contents), '[');

        if ($looksLikeJson) {
            $decoded = json_decode($contents, true);

            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['file' => 'ملف JSON غير صالح.']);
            }

            return array_map(fn ($row) => [
                'timestamp_seconds' => $row['timestamp_seconds'] ?? null,
                'question' => $row['question'] ?? null,
                'choices' => array_values(array_filter(
                    is_array($row['choices'] ?? null) ? $row['choices'] : [],
                    fn ($choice) => $choice !== null && $choice !== ''
                )),
                'correct_index' => $row['correct_index'] ?? null,
            ], $decoded);
        }

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", str_replace("\r\n", "\n", $contents))),
            fn ($line) => $line !== ''
        ));

        $header = null;
        $rows = [];

        foreach ($lines as $line) {
            $columns = str_getcsv($line);

            if ($header === null) {
                $header = array_map(fn ($col) => strtolower(trim($col)), $columns);

                continue;
            }

            $record = array_combine($header, array_pad(array_slice($columns, 0, count($header)), count($header), ''));

            $choices = [];
            foreach ($record as $key => $value) {
                if (str_starts_with($key, 'choice') && trim((string) $value) !== '') {
                    $choices[] = trim((string) $value);
                }
            }

            $rows[] = [
                'timestamp_seconds' => isset($record['timestamp_seconds']) && $record['timestamp_seconds'] !== ''
                    ? (int) $record['timestamp_seconds'] : null,
                'question' => $record['question'] !== '' ? ($record['question'] ?? null) : null,
                'choices' => $choices,
                'correct_index' => isset($record['correct_index']) && $record['correct_index'] !== ''
                    ? (int) $record['correct_index'] : null,
            ];
        }

        return $rows;
    }

    private function assertCanManage(Request $request): void
    {
        if (! $request->user()->canPerform('manage_questions')) {
            throw new AuthorizationException('لا تملك صلاحية إدارة أسئلة الفيديو التفاعلي.');
        }
    }

    private function assertEnabled(): void
    {
        abort_unless((bool) Setting::get('interactive_video_enabled', false), 404);
    }
}
