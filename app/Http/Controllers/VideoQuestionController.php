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
use Illuminate\Support\Facades\DB;
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
