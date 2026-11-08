<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OfflineFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_emergency_answers_file_goes_into_the_attempt(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        $student = User::factory()->create();
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => [['type' => 'short', 'points' => 2, 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد']]]],
        ]);
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $qid = $exam->questions()->value('id');

        $token = $attempt->offlineToken();
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('offline_token', $token));

        $file = fn (array $data) => UploadedFile::fake()->createWithContent('answers.json', json_encode($data));
        $good = ['kind' => 'examnest-offline-answers', 'attempt' => $attempt->id, 'token' => $token, 'saved_at' => now()->toIso8601String(),
            'answers' => [$qid => ['response' => ['text' => 'بغداد'], 'flagged' => false], 999999 => ['response' => ['text' => 'x']]]];

        // A changed token, or another exam's attempt, is refused.
        $this->actingAs($teacher)->post(route('exams.monitor.offline-file', $exam), ['file' => $file(['token' => str_repeat('0', 64)] + $good)])->assertStatus(422);
        $other = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'آخر'], 'languages' => ['ar']]);
        $this->actingAs($teacher)->post(route('exams.monitor.offline-file', $other), ['file' => $file($good)])->assertStatus(422);

        $this->actingAs($teacher)->post(route('exams.monitor.offline-file', $exam), ['file' => $file($good), 'submit' => 1])
            ->assertOk()->assertJson(['answers' => 1]);
        $attempt->refresh();
        $this->assertSame(2.0, $attempt->score);
        $this->assertSame(1, $attempt->events()->where('type', 'offline_file')->count());
        // Once submitted, a second file needs the attempt reopened first.
        $this->actingAs($teacher)->post(route('exams.monitor.offline-file', $exam), ['file' => $file($good)])->assertStatus(409);
    }
}
