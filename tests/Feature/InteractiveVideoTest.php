<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Setting;
use App\Models\User;
use App\Models\VideoComment;
use App\Models\VideoQuestion;
use App\Models\VideoQuestionChoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InteractiveVideoTest extends TestCase
{
    use RefreshDatabase;

    private function videoFile(User $user): File
    {
        return File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4']);
    }

    public function test_questions_endpoint_is_a_404_when_the_feature_is_disabled(): void
    {
        Setting::set('interactive_video_enabled', false);
        $user = User::factory()->create();
        $file = $this->videoFile($user);

        $this->actingAs($user)->getJson(route('questions.index', $file))->assertNotFound();
    }

    public function test_user_with_manage_questions_can_create_a_question_with_choices(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create();
        $file = $this->videoFile($user);

        $this->actingAs($user)->post(route('questions.store', $file), [
            'timestamp_seconds' => 30,
            'question' => 'ما نتيجة 2 + 2؟',
            'choices' => ['3', '4', '5'],
            'correct_index' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('video_questions', ['file_id' => $file->id, 'timestamp_seconds' => 30]);
        $question = VideoQuestion::where('file_id', $file->id)->firstOrFail();
        $this->assertSame(3, $question->choices()->count());
        $this->assertDatabaseHas('video_question_choices', [
            'video_question_id' => $question->id,
            'label' => '4',
            'is_correct' => true,
        ]);
    }

    public function test_a_user_without_manage_questions_capability_cannot_create_a_question(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create(['role' => 'contributor']);
        $file = $this->videoFile($user);

        $this->actingAs($user)->post(route('questions.store', $file), [
            'timestamp_seconds' => 10,
            'question' => 'سؤال',
            'choices' => ['أ', 'ب'],
            'correct_index' => 0,
        ])->assertForbidden();
    }

    public function test_choices_hide_the_correct_answer_from_a_viewer_without_manage_questions(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create(['role' => 'contributor']);
        $file = $this->videoFile($user);
        $question = VideoQuestion::create(['file_id' => $file->id, 'timestamp_seconds' => 5, 'question' => 'س']);
        VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'أ', 'is_correct' => true, 'order' => 0]);
        VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'ب', 'is_correct' => false, 'order' => 1]);

        $response = $this->actingAs($user)->getJson(route('questions.index', $file))->assertOk()->json();

        $this->assertNull($response['questions'][0]['choices'][0]['is_correct']);
    }

    public function test_choices_reveal_the_correct_answer_to_a_manager(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create(); // default role 'user' has manage_questions
        $file = $this->videoFile($user);
        $question = VideoQuestion::create(['file_id' => $file->id, 'timestamp_seconds' => 5, 'question' => 'س']);
        VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'أ', 'is_correct' => true, 'order' => 0]);

        $response = $this->actingAs($user)->getJson(route('questions.index', $file))->assertOk()->json();

        $this->assertTrue($response['questions'][0]['choices'][0]['is_correct']);
    }

    public function test_answering_a_question_records_correctness_and_updates_the_running_score(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $question = VideoQuestion::create(['file_id' => $file->id, 'timestamp_seconds' => 5, 'question' => 'س']);
        $correct = VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'صح', 'is_correct' => true, 'order' => 0]);
        VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'خطأ', 'is_correct' => false, 'order' => 1]);

        $response = $this->actingAs($user)->postJson(route('questions.answer', $question), [
            'choice_id' => $correct->id,
        ])->assertOk()->json();

        $this->assertTrue($response['is_correct']);
        $this->assertSame(['answered' => 1, 'correct' => 1, 'total' => 1], $response['score']);
        $this->assertDatabaseHas('video_question_answers', [
            'video_question_id' => $question->id,
            'user_id' => $user->id,
            'is_correct' => true,
        ]);
    }

    public function test_a_question_cannot_be_answered_twice_by_the_same_user(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $question = VideoQuestion::create(['file_id' => $file->id, 'timestamp_seconds' => 5, 'question' => 'س']);
        $choice = VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'صح', 'is_correct' => true, 'order' => 0]);

        $this->actingAs($user)->postJson(route('questions.answer', $question), ['choice_id' => $choice->id])->assertOk();
        $this->actingAs($user)->postJson(route('questions.answer', $question), ['choice_id' => $choice->id])->assertStatus(422);
    }

    public function test_user_cannot_manage_or_view_questions_on_another_users_file(): void
    {
        Setting::set('interactive_video_enabled', true);
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $file = $this->videoFile($owner);

        $this->actingAs($intruder)->getJson(route('questions.index', $file))->assertForbidden();
        $this->actingAs($intruder)->post(route('questions.store', $file), [
            'timestamp_seconds' => 1, 'question' => 'س', 'choices' => ['أ', 'ب'], 'correct_index' => 0,
        ])->assertForbidden();
    }

    public function test_user_can_post_and_view_a_comment_on_their_own_video(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);

        $this->actingAs($user)->post(route('comments.store', $file), [
            'body' => 'ملاحظة رائعة هنا',
            'timestamp_seconds' => 42,
        ])->assertRedirect();

        $response = $this->actingAs($user)->getJson(route('comments.index', $file))->assertOk()->json();
        $this->assertCount(1, $response['comments']);
        $this->assertSame(42, $response['comments'][0]['timestamp_seconds']);
    }

    public function test_a_user_without_comment_capability_cannot_post_a_comment(): void
    {
        $user = User::factory()->create(['role' => 'guest']);
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4']);

        $this->actingAs($user)->post(route('comments.store', $file), ['body' => 'تعليق'])->assertForbidden();
    }

    public function test_comment_author_can_delete_their_own_comment(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $comment = VideoComment::create(['file_id' => $file->id, 'user_id' => $user->id, 'body' => 'تعليق']);

        $this->actingAs($user)->delete(route('comments.destroy', $comment))->assertRedirect();
        $this->assertDatabaseMissing('video_comments', ['id' => $comment->id]);
    }

    public function test_another_user_cannot_delete_someone_elses_comment_on_a_video_they_do_not_own(): void
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();
        $intruder = User::factory()->create();
        $file = $this->videoFile($owner);
        $comment = VideoComment::create(['file_id' => $file->id, 'user_id' => $commenter->id, 'body' => 'تعليق']);

        $this->actingAs($intruder)->delete(route('comments.destroy', $comment))->assertForbidden();
    }
}
