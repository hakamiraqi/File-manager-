<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Setting;
use App\Models\VideoQuestion;
use App\Models\VideoQuestionAnswer;
use App\Models\VideoQuestionChoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * JSON mirror of App\Http\Controllers\VideoQuestionController for the
 * Flutter app — same validation, authorization, and score/answer-key
 * rules, just JSON responses instead of Inertia redirects. The response
 * shape from index() is also exactly what the app caches locally to let
 * a downloaded video's quiz work offline (see VideoCommentController's
 * mobile counterpart for the paired comments endpoints).
 */
class VideoQuestionController extends Controller
{
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

    public function store(Request $request, File $file): JsonResponse
    {
        $this->authorize('update', $file);
        $this->assertEnabled();
        $this->assertCanManage($request);

        $data = $this->validateQuestion($request);

        $question = DB::transaction(function () use ($data, $file) {
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

            return $question;
        });

        ActivityLog::record('video_question.created', "أضاف سؤالاً تفاعلياً للفيديو \"{$file->name}\"", $file);

        return response()->json([
            'question' => $question->load('choices'),
        ], 201);
    }

    public function update(Request $request, VideoQuestion $question): JsonResponse
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

        return response()->json(['question' => $question->fresh('choices')]);
    }

    public function destroy(Request $request, VideoQuestion $question): JsonResponse
    {
        $file = $question->file;
        $this->authorize('update', $file);
        $this->assertCanManage($request);

        $question->delete();

        ActivityLog::record('video_question.deleted', "حذف سؤالاً تفاعلياً من الفيديو \"{$file->name}\"", $file);

        return response()->json(['message' => 'تم حذف السؤال.']);
    }

    /**
     * Same "one final answer per user" rule as the web endpoint — the
     * Flutter app calls this both live (online playback) and as the sync
     * step after a batch of answers were recorded locally while offline.
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
