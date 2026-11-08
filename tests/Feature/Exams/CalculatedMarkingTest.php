<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Answer;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use App\Services\Exams\Calculated;
use App\Services\Exams\Formula;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use Tests\TestCase;

class CalculatedMarkingTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->create(['is_examiner' => true]);
    }

    private function exam(array $questions, array $settings = []): Exam
    {
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $settings + [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 5,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'show_answers' => true, 'is_published' => true,
            'questions' => $questions,
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_the_formula_calculator(): void
    {
        $this->assertEquals(14, Formula::evaluate('2 + 3 * 4'));
        $this->assertEquals(20, Formula::evaluate('(2 + 3) * 4'));
        $this->assertEquals(512, Formula::evaluate('2 ^ 3 ^ 2'));
        $this->assertEquals(-4, Formula::evaluate('-2 ^ 2'));   // as in mathematics: −(2²)
        $this->assertEquals(0.5, Formula::evaluate('2 ^ -1'));
        $this->assertEquals(5, Formula::evaluate('sqrt(a^2 + b^2)', ['a' => 3, 'b' => 4]));
        $this->assertEqualsWithDelta(78.54, Formula::evaluate('pi * r ^ 2', ['R' => 5]), 0.01);
        $this->assertEquals(3.14, Formula::evaluate('round(pi, 2)'));
        $this->assertEquals(2, Formula::evaluate('log(100)'));
        $this->assertEquals(6, Formula::evaluate('max(1, 6, 3)'));
        $this->assertEquals(12, Formula::evaluate('3 × 4'));
        $this->assertSame(['v', 't'], Formula::variables('round(v * t, 2) + pi'));

        foreach (['2 +', '(1 + 2', 'x + 1', '1 / 0', 'sqrt(-1)', 'system(1)', '2; 3', '`ls`'] as $bad) {
            try {
                Formula::evaluate($bad);
                $this->fail("«{$bad}» should be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_each_attempt_has_its_own_numbers_and_is_marked_on_them(): void
    {
        $exam = $this->exam([[
            'type' => 'calculated', 'points' => 2, 'body' => ['ar' => 'سرعة {v} م/ث لمدة {t} ث. المسافة؟'],
            'settings' => ['vars' => [['name' => 'v', 'min' => 10, 'max' => 30, 'decimals' => 0], ['name' => 't', 'min' => 2, 'max' => 9, 'decimals' => 1]],
                'formula' => 'v * t', 'tolerance' => '1%'],
        ]]);
        $q = $exam->questions()->first();
        $student = User::factory()->create();

        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $values = Calculated::values($q, $attempt->id);
        $this->assertTrue($values['v'] >= 10 && $values['v'] <= 30 && $values['t'] >= 2 && $values['t'] <= 9);
        $this->assertSame($values, Calculated::values($q, $attempt->id));   // the same on every load

        $body = 'سرعة '.Calculated::format($values['v']).' م/ث لمدة '.Calculated::format($values['t']).' ث. المسافة؟';
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('questions.0.body', $body)->missing('questions.0.settings'));

        // Within 1 %, typed with Arabic digits.
        $answer = $values['v'] * $values['t'] * 1.005;
        $arabic = strtr(number_format($answer, 2, '٫', ''), ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $q->id, 'response' => ['value' => $arabic]]);
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));
        $this->assertSame(2.0, $attempt->fresh()->score);

        $this->actingAs($student)->get(route('exams.attempts.result', $attempt))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('review.0.body', $body)
                ->where('review.0.correct', [Calculated::format($values['v'] * $values['t']).' ± 1%']));

        // Another attempt, other numbers; the old answer is now wrong for them (almost always).
        $this->actingAs($student)->post(route('exams.start', $exam));
        $second = Attempt::where('number', 2)->firstOrFail();
        $this->assertNotSame($values, Calculated::values($q, $second->id));
    }

    public function test_the_editor_checks_calculated_questions(): void
    {
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $put = fn (array $settings, string $body = 'قيمة {a} و {b}') => $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit',
            'questions' => [['type' => 'calculated', 'body' => ['ar' => $body], 'settings' => $settings]],
        ]);
        $vars = [['name' => 'a', 'min' => 1, 'max' => 5], ['name' => 'b', 'min' => 0, 'max' => 5]];

        $put(['vars' => $vars, 'formula' => 'a + c'])->assertSessionHasErrors('questions.0.settings');
        $put(['vars' => $vars, 'formula' => 'a / b'])->assertSessionHasErrors('questions.0.settings');   // b can be 0
        $put(['vars' => $vars, 'formula' => 'a * b'], 'قيمة {a} فقط')->assertSessionHasErrors('questions.0.settings');
        $put(['vars' => [['name' => 'a', 'min' => 5, 'max' => 1]], 'formula' => 'a'], 'قيمة {a}')->assertSessionHasErrors('questions.0.settings');
        $put(['vars' => $vars, 'formula' => 'a * b', 'tolerance' => 'كثير'])->assertSessionHasErrors('questions.0.settings');
        $put(['vars' => $vars, 'formula' => 'a * b', 'tolerance' => '0.5'])->assertSessionHasNoErrors();
    }

    public function test_double_marking_averages_close_marks_and_waits_on_far_ones(): void
    {
        $exam = $this->exam([['type' => 'essay', 'points' => 10, 'body' => ['ar' => 'اشرح']]], ['double_marking' => true, 'marking_tolerance' => 20]);
        $second = User::factory()->create(['is_examiner' => true]);
        $outsider = User::factory()->create(['is_examiner' => true]);

        $students = User::factory()->count(2)->create();
        foreach ($students as $student) {
            $this->actingAs($student)->post(route('exams.start', $exam));
            $attempt = Attempt::where('user_id', $student->id)->firstOrFail();
            $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $exam->questions()->value('id'), 'response' => ['text' => 'الأكسدة فقدان إلكترونات']]);
            $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));
        }
        [$a1, $a2] = Answer::orderBy('id')->get()->all();

        // Only the owner (and admins) invite markers; an outsider sees nothing.
        $this->actingAs($outsider)->get(route('exams.manage.results', $exam))->assertNotFound();
        $this->actingAs($this->teacher)->post(route('exams.manage.markers.store', $exam), ['email' => $second->email])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post(route('exams.manage.markers.store', $exam), ['email' => $students[0]->email])->assertSessionHasErrors('email');
        $this->actingAs($second)->get(route('exams.manage.edit', $exam))->assertNotFound();
        $this->actingAs($second)->get(route('exams.manage.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('exams.0.can_edit', false));

        // Close marks: the average. The second marker does not see the first mark before marking.
        $this->actingAs($this->teacher)->put(route('exams.manage.answers.grade', $a1), ['points' => 7]);
        $this->assertNull($a1->fresh()->points);
        $this->actingAs($second)->get(route('exams.manage.results', $exam))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('can_manage', false)->where('attempts.0.essays.0.status', 'second_needed')->where('attempts.0.essays.0.marks', []));
        $this->actingAs($second)->put(route('exams.manage.answers.grade', $a1), ['points' => 8]);
        $this->assertEquals(7.5, $a1->fresh()->points);
        $this->assertFalse($a1->attempt->fresh()->needs_grading);

        // Far apart: no points until the owner settles it; a marker cannot settle.
        $this->actingAs($this->teacher)->put(route('exams.manage.answers.grade', $a2), ['points' => 2]);
        $this->actingAs($second)->put(route('exams.manage.answers.grade', $a2), ['points' => 9]);
        $this->assertNull($a2->fresh()->points);
        $this->assertTrue($a2->attempt->fresh()->needs_grading);
        $this->actingAs($second)->put(route('exams.manage.answers.grade', $a2), ['points' => 9, 'final' => true]);
        $this->assertNull($a2->fresh()->points);
        $this->actingAs($this->teacher)->get(route('exams.manage.results', $exam))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('attempts.1.essays.0.status', 'disputed')->has('attempts.1.essays.0.marks', 2));
        $this->actingAs($this->teacher)->put(route('exams.manage.answers.grade', $a2), ['points' => 6, 'final' => true]);
        $this->assertEquals(6, $a2->fresh()->points);
        $this->assertEquals(6, $a2->attempt->fresh()->score);
        $this->assertFalse($a2->attempt->fresh()->needs_grading);
    }
}
