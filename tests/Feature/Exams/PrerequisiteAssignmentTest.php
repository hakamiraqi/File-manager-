<?php

namespace Tests\Feature\Exams;

use App\Http\Controllers\Exams\BankController;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PrerequisiteAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private function exam(array $settings = []): Exam
    {
        $this->teacher ??= User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $settings + [
            'title' => ['ar' => $settings['title_ar'] ?? 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'duration_minutes' => 30,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => [['type' => 'short', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد']]]],
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_the_practice_exam_comes_first(): void
    {
        $practice = $this->exam(['is_practice' => true, 'title_ar' => 'تجريبي']);
        $final = $this->exam(['requires_exam_id' => $practice->id]);
        $this->assertSame($practice->id, $final->requires_exam_id);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $final), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'grading_method' => 'highest', 'show_results' => 'after_submit',
            'requires_exam_id' => $final->id, 'questions' => [['type' => 'short', 'points' => 1, 'body' => ['ar' => 'س'], 'settings' => ['answers' => ['ج']]]],
        ])->assertSessionHasErrors('requires_exam_id');

        $student = User::factory()->create();
        $this->actingAs($student)->get(route('exams.show', $final))->assertInertia(fn (AssertableInertia $p) => $p->where('exam.prerequisite.id', $practice->id));
        $this->actingAs($student)->post(route('exams.start', $final))->assertSessionHasErrors('exam');
        $this->assertSame(0, $final->attempts()->count());

        $this->actingAs($student)->post(route('exams.start', $practice));
        $this->actingAs($student)->post(route('exams.attempts.submit', Attempt::firstOrFail()));
        $this->actingAs($student)->get(route('exams.show', $final))->assertInertia(fn (AssertableInertia $p) => $p->where('exam.prerequisite', null));
        $this->actingAs($student)->post(route('exams.start', $final));
        $this->assertSame(1, $final->attempts()->count());
    }

    public function test_an_assignment_has_a_deadline_and_no_hall_locks(): void
    {
        $exam = $this->exam(['is_assignment' => true, 'duration_minutes' => null, 'resume_code_required' => true, 'protect_content' => true,
            'opens_at' => now()->subHour()->format('Y-m-d\TH:i'), 'closes_at' => now()->addDays(3)->format('Y-m-d\TH:i')]);
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $this->assertTrue($attempt->deadline_at->gt(now()->addDays(2)));
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('exam.assignment', true)->where('hall.protect', false)->where('hall.locked', false));
        $this->actingAs($student)->postJson(route('exams.attempts.event', $attempt), ['type' => 'hidden']);
        $this->flushSession();
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertInertia(fn (AssertableInertia $p) => $p->where('hall.locked', false));
    }

    public function test_a_wrong_computer_clock_shows_in_the_monitor(): void
    {
        $exam = $this->exam();
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $res = $this->actingAs($student)->postJson(route('exams.attempts.ping', $attempt), ['client_time' => now()->getTimestampMs() - 5 * 60 * 1000])->assertOk();
        $this->assertIsInt($res->json('server_time'));
        $row = collect($this->actingAs($this->teacher)->getJson(route('exams.monitor.data', $exam))->json('rows'))->firstWhere('user.id', $student->id);
        $this->assertEqualsWithDelta(-300, $row['attempt']['clock'], 2);

        $this->actingAs($student)->postJson(route('exams.attempts.ping', $attempt), ['client_time' => now()->getTimestampMs()]);
        $row = collect($this->actingAs($this->teacher)->getJson(route('exams.monitor.data', $exam))->json('rows'))->firstWhere('user.id', $student->id);
        $this->assertNull($row['attempt']['clock']);
    }

    public function test_bank_questions_report_how_they_did(): void
    {
        $exam = $this->exam();
        $q = Question::create(['exam_id' => null, 'type' => 'short', 'body' => ['ar' => 'س'], 'points' => 2]);
        $students = User::factory()->count(12)->create();
        foreach ($students as $i => $u) {
            $a = Attempt::create(['exam_id' => $exam->id, 'user_id' => $u->id, 'number' => 1, 'started_at' => now(), 'submitted_at' => now(),
                'score' => $i < 6 ? 9 : 1, 'max_score' => 10]);
            DB::table('exam_answers')->insert(['exam_attempt_id' => $a->id, 'exam_question_id' => $q->id, 'points' => $i < 6 ? 2 : 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $quality = BankController::quality(collect([$q]))[$q->id];
        $this->assertSame(12, $quality['uses']);
        $this->assertEquals(0.5, $quality['facility']);
        $this->assertGreaterThan(0.9, $quality['discrimination']);
        $this->assertSame([], $quality['flags']);
    }
}
