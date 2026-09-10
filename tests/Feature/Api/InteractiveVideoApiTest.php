<?php

namespace Tests\Feature\Api;

use App\Models\File;
use App\Models\Setting;
use App\Models\User;
use App\Models\VideoComment;
use App\Models\VideoQuestion;
use App\Models\VideoQuestionChoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InteractiveVideoApiTest extends TestCase
{
    use RefreshDatabase;

    private function videoFile(User $user): File
    {
        return File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4']);
    }

    public function test_questions_endpoint_is_a_404_via_the_api_when_the_feature_is_disabled(): void
    {
        Setting::set('interactive_video_enabled', false);
        $user = User::factory()->create();
        $file = $this->videoFile($user);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/files/{$file->id}/questions")->assertNotFound();
    }

    public function test_a_manager_can_create_a_question_with_choices_via_the_api(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create();
        $file = $this->videoFile($user);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/files/{$file->id}/questions", [
            'timestamp_seconds' => 30,
            'question' => 'ما نتيجة 2 + 2؟',
            'choices' => ['3', '4', '5'],
            'correct_index' => 1,
        ]);

        $response->assertCreated()->assertJsonPath('question.question', 'ما نتيجة 2 + 2؟');
        $this->assertDatabaseHas('video_questions', ['file_id' => $file->id, 'timestamp_seconds' => 30]);
    }

    public function test_a_user_without_manage_questions_cannot_create_a_question_via_the_api(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create(['role' => 'contributor']);
        $file = $this->videoFile($user);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/files/{$file->id}/questions", [
            'timestamp_seconds' => 10,
            'question' => 'سؤال',
            'choices' => ['أ', 'ب'],
            'correct_index' => 0,
        ])->assertForbidden();
    }

    public function test_the_index_payload_hides_the_correct_answer_from_a_viewer_without_manage_questions(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create(['role' => 'contributor']);
        $file = $this->videoFile($user);
        $question = VideoQuestion::create(['file_id' => $file->id, 'timestamp_seconds' => 5, 'question' => 'س']);
        VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'أ', 'is_correct' => true, 'order' => 0]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/files/{$file->id}/questions")->assertOk()->json();

        $this->assertNull($response['questions'][0]['choices'][0]['is_correct']);
    }

    public function test_a_question_can_be_updated_and_deleted_via_the_api(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $question = VideoQuestion::create(['file_id' => $file->id, 'timestamp_seconds' => 5, 'question' => 'قديم']);
        VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'أ', 'is_correct' => true, 'order' => 0]);
        VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'ب', 'is_correct' => false, 'order' => 1]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/questions/{$question->id}", [
            'timestamp_seconds' => 12,
            'question' => 'جديد',
            'choices' => ['ج', 'د'],
            'correct_index' => 0,
        ])->assertOk()->assertJsonPath('question.question', 'جديد');

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/questions/{$question->id}")->assertOk();
        $this->assertDatabaseMissing('video_questions', ['id' => $question->id]);
    }

    public function test_answering_a_question_via_the_api_records_correctness_and_updates_the_score(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $question = VideoQuestion::create(['file_id' => $file->id, 'timestamp_seconds' => 5, 'question' => 'س']);
        $correct = VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'صح', 'is_correct' => true, 'order' => 0]);
        VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'خطأ', 'is_correct' => false, 'order' => 1]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/questions/{$question->id}/answer", [
            'choice_id' => $correct->id,
        ])->assertOk()->json();

        $this->assertTrue($response['is_correct']);
        $this->assertSame(['answered' => 1, 'correct' => 1, 'total' => 1], $response['score']);
    }

    public function test_a_question_cannot_be_answered_twice_via_the_api(): void
    {
        Setting::set('interactive_video_enabled', true);
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $question = VideoQuestion::create(['file_id' => $file->id, 'timestamp_seconds' => 5, 'question' => 'س']);
        $choice = VideoQuestionChoice::create(['video_question_id' => $question->id, 'label' => 'صح', 'is_correct' => true, 'order' => 0]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/questions/{$question->id}/answer", ['choice_id' => $choice->id])->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/questions/{$question->id}/answer", ['choice_id' => $choice->id])->assertStatus(422);
    }

    public function test_a_user_cannot_view_or_manage_questions_on_another_users_file_via_the_api(): void
    {
        Setting::set('interactive_video_enabled', true);
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $file = $this->videoFile($owner);

        $this->actingAs($intruder, 'sanctum')->getJson("/api/v1/files/{$file->id}/questions")->assertForbidden();
    }

    public function test_a_user_can_post_and_list_comments_via_the_api(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/files/{$file->id}/comments", [
            'body' => 'ملاحظة رائعة',
            'timestamp_seconds' => 42,
        ])->assertCreated();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/files/{$file->id}/comments")->assertOk()->json();
        $this->assertCount(1, $response['comments']);
        $this->assertSame(42, $response['comments'][0]['timestamp_seconds']);
    }

    public function test_a_user_without_comment_capability_cannot_post_a_comment_via_the_api(): void
    {
        $user = User::factory()->create(['role' => 'guest']);
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4']);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/files/{$file->id}/comments", ['body' => 'تعليق'])->assertForbidden();
    }

    public function test_a_comment_author_can_delete_their_own_comment_via_the_api(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $comment = VideoComment::create(['file_id' => $file->id, 'user_id' => $user->id, 'body' => 'تعليق']);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/comments/{$comment->id}")->assertOk();
        $this->assertDatabaseMissing('video_comments', ['id' => $comment->id]);
    }

    public function test_another_user_cannot_delete_someone_elses_comment_via_the_api(): void
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();
        $intruder = User::factory()->create();
        $file = $this->videoFile($owner);
        $comment = VideoComment::create(['file_id' => $file->id, 'user_id' => $commenter->id, 'body' => 'تعليق']);

        $this->actingAs($intruder, 'sanctum')->deleteJson("/api/v1/comments/{$comment->id}")->assertForbidden();
    }
}
