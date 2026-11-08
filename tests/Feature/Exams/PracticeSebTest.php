<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PracticeSebTest extends TestCase
{
    use RefreshDatabase;

    private function exam(array $settings = []): Exam
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'تدريب'], 'languages' => ['ar']]);
        $this->actingAs($teacher)->put(route('exams.manage.update', $exam), $settings + [
            'title' => ['ar' => 'تدريب'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => [
                ['type' => 'single', 'points' => 2, 'body' => ['ar' => 'رمز الماء؟'], 'feedback' => ['ar' => 'H2O: ذرتا هيدروجين'], 'choices' => [
                    ['body' => ['ar' => 'H2O'], 'is_correct' => true], ['body' => ['ar' => 'CO2'], 'is_correct' => false],
                ]],
                ['type' => 'essay', 'points' => 3, 'body' => ['ar' => 'اشرح']],
            ],
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_a_practice_answer_is_checked_once_and_then_fixed(): void
    {
        $exam = $this->exam(['is_practice' => true]);
        [$single, $essay] = $exam->questions()->get()->all();
        $right = Choice::where('exam_question_id', $single->id)->where('is_correct', true)->value('id');
        $wrong = Choice::where('exam_question_id', $single->id)->where('is_correct', false)->value('id');
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();

        $this->actingAs($student)->postJson(route('exams.attempts.check', $attempt), ['question_id' => $single->id, 'response' => ['choice' => $wrong]])
            ->assertOk()->assertJson(['fraction' => 0, 'correct' => ['H2O'], 'feedback' => 'H2O: ذرتا هيدروجين']);
        // Neither a second check nor an autosave changes it.
        $this->actingAs($student)->postJson(route('exams.attempts.check', $attempt), ['question_id' => $single->id, 'response' => ['choice' => $right]])
            ->assertJson(['fraction' => 0]);
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $single->id, 'response' => ['choice' => $right]])->assertOk();
        $this->actingAs($student)->postJson(route('exams.attempts.check', $attempt), ['question_id' => $essay->id, 'response' => ['text' => 'x']])->assertStatus(422);

        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('exam.practice', true)->where("answers.{$single->id}.checked.fraction", fn ($f) => $f == 0));

        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));
        $this->assertSame(0.0, $attempt->fresh()->score);
    }

    public function test_checking_is_only_for_practice_exams(): void
    {
        $exam = $this->exam();
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $this->actingAs($student)->postJson(route('exams.attempts.check', Attempt::firstOrFail()), ['question_id' => $exam->questions()->value('id')])->assertNotFound();
    }

    public function test_safe_exam_browser_with_and_without_keys(): void
    {
        $exam = $this->exam(['seb_required' => true]);
        $student = User::factory()->create();
        $seb = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 SEB/3.7'];

        $this->actingAs($student)->get(route('exams.show', $exam))->assertInertia(fn (AssertableInertia $p) => $p->where('exam.seb_required', true)->where('exam.in_seb', false));
        $this->actingAs($student)->from(route('exams.show', $exam))->post(route('exams.start', $exam))->assertSessionHasErrors('exam');
        $this->assertSame(0, Attempt::count());

        $this->actingAs($student)->withHeaders($seb)->post(route('exams.start', $exam))->assertRedirect();
        $attempt = Attempt::firstOrFail();
        $this->actingAs($student)->withHeaders($seb)->get(route('exams.attempts.take', $attempt))->assertOk();
        // The same attempt from an ordinary browser is turned away.
        $this->actingAs($student)->withHeaders(['User-Agent' => 'Mozilla/5.0 Chrome/120'])->get(route('exams.attempts.take', $attempt))
            ->assertRedirect(route('exams.show', $exam));

        // With a Config Key only the right SEB setup gets in.
        $exam->update(['seb_keys' => "abc123\nsecond-key"]);
        $url = route('exams.attempts.take', $attempt);
        $this->actingAs($student)->withHeaders($seb + ['X-SafeExamBrowser-ConfigKeyHash' => hash('sha256', $url.'wrong')])->get($url)->assertRedirect();
        $this->actingAs($student)->withHeaders($seb + ['X-SafeExamBrowser-ConfigKeyHash' => hash('sha256', $url.'second-key')])->get($url)->assertOk();
    }
}
