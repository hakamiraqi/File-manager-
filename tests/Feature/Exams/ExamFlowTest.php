<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use App\Services\Exams\Grader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ExamFlowTest extends TestCase
{
    use RefreshDatabase;

    private function examiner(): User
    {
        return User::factory()->create(['is_examiner' => true]);
    }

    /** An exam with one question of each auto-marked kind and an essay, saved through the editor. */
    private function buildExam(User $teacher, array $overrides = []): Exam
    {
        $this->actingAs($teacher)->post(route('exams.manage.store'))->assertRedirect();
        $exam = Exam::latest('id')->first();

        $this->actingAs($teacher)->put(route('exams.manage.update', $exam), array_merge([
            'title' => ['ar' => 'امتحان الكيمياء', 'en' => 'Chemistry exam'],
            'languages' => ['ar', 'en'],
            'direction' => 'auto',
            'duration_minutes' => 30,
            'max_attempts' => 1,
            'grading_method' => 'highest',
            'show_results' => 'after_submit',
            'show_answers' => true,
            'shuffle_questions' => false,
            'shuffle_choices' => false,
            'is_published' => true,
            'questions' => [
                ['type' => 'single', 'points' => 2, 'body' => ['ar' => 'رمز الماء؟', 'en' => 'Water?'], 'choices' => [
                    ['body' => ['ar' => 'H2O'], 'is_correct' => true], ['body' => ['ar' => 'CO2'], 'is_correct' => false],
                ]],
                ['type' => 'multiple', 'points' => 2, 'body' => ['ar' => 'الغازات النبيلة'], 'choices' => [
                    ['body' => ['ar' => 'هيليوم'], 'is_correct' => true], ['body' => ['ar' => 'نيون'], 'is_correct' => true], ['body' => ['ar' => 'حديد'], 'is_correct' => false],
                ]],
                ['type' => 'true_false', 'points' => 1, 'body' => ['ar' => 'الماء يغلي عند 100'], 'choices' => [
                    ['body' => ['ar' => 'صح'], 'is_correct' => true], ['body' => ['ar' => 'خطأ'], 'is_correct' => false],
                ]],
                ['type' => 'short', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق'], 'settings' => ['answers' => ['بغداد']]],
                ['type' => 'numeric', 'points' => 1, 'body' => ['ar' => 'π لرقمين'], 'settings' => ['answer' => '3.14', 'tolerance' => '0.01']],
                ['type' => 'matching', 'points' => 2, 'body' => ['ar' => 'طابق'], 'choices' => [
                    ['body' => ['ar' => 'Na'], 'match' => ['ar' => 'صوديوم']], ['body' => ['ar' => 'K'], 'match' => ['ar' => 'بوتاسيوم']],
                ]],
                ['type' => 'ordering', 'points' => 1, 'body' => ['ar' => 'رتّب'], 'choices' => [
                    ['body' => ['ar' => 'أ']], ['body' => ['ar' => 'ب']], ['body' => ['ar' => 'ج']],
                ]],
                ['type' => 'description', 'body' => ['ar' => 'اقرأ النص التالي']],
                ['type' => 'essay', 'points' => 5, 'body' => ['ar' => 'اشرح التأكسد']],
            ],
        ], $overrides))->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_examiner_builds_an_exam_with_every_kind_of_question(): void
    {
        $exam = $this->buildExam($this->examiner());

        $this->assertSame(['ar' => 'امتحان الكيمياء', 'en' => 'Chemistry exam'], $exam->title);
        $this->assertCount(9, $exam->questions);
        $this->assertSame(15.0, $exam->maxScore());
        $this->assertSame('Chemistry exam', $exam->text('title', 'en'));
    }

    public function test_the_editor_rejects_a_choice_question_without_a_correct_answer(): void
    {
        $teacher = $this->examiner();
        $exam = $this->buildExam($teacher);

        $this->actingAs($teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'x'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit',
            'questions' => [['type' => 'single', 'points' => 1, 'body' => ['ar' => 'س'], 'choices' => [
                ['body' => ['ar' => 'أ'], 'is_correct' => false], ['body' => ['ar' => 'ب'], 'is_correct' => false],
            ]]],
        ])->assertSessionHasErrors('questions.0.choices');
        $this->assertCount(9, $exam->fresh()->questions);
    }

    public function test_a_student_sits_the_exam_and_is_marked_automatically(): void
    {
        $exam = $this->buildExam($this->examiner());
        $student = User::factory()->create();
        $q = $exam->questions()->with('choices')->get()->keyBy('type');

        $this->actingAs($student)->get(route('exams.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Exams/Index')->has('exams', 1));

        $this->actingAs($student)->post(route('exams.start', $exam))->assertRedirect();
        $attempt = Attempt::where('user_id', $student->id)->firstOrFail();

        // The page never carries the answers.
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Exams/Take')->has('questions', 9)
                ->where('questions.0.choices.0', fn ($c) => ! isset($c['is_correct'])));

        $save = fn ($type, $response) => $this->actingAs($student)
            ->postJson(route('exams.attempts.save', $attempt), ['question_id' => $q[$type]->id, 'response' => $response])->assertOk();

        $save('single', ['choice' => $q['single']->choices->firstWhere('is_correct', true)->id]);
        $save('multiple', ['choices' => [$q['multiple']->choices[0]->id]]);                 // 1 of 2 right → half
        $save('true_false', ['choice' => $q['true_false']->choices->firstWhere('is_correct', false)->id]);
        $save('short', ['text' => '  بغدادُ ']);                                              // diacritics ignored
        $save('numeric', ['value' => '٣٫١٤٥']);                                               // Arabic digits and separator
        $m = $q['matching']->choices;
        $save('matching', ['pairs' => [$m[0]->id => $m[0]->id, $m[1]->id => $m[0]->id]]);   // 1 of 2
        $save('ordering', ['order' => $q['ordering']->choices->pluck('id')->all()]);
        $save('essay', ['text' => 'التأكسد فقدان الإلكترونات']);

        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt))->assertRedirect(route('exams.attempts.result', $attempt));
        $attempt->refresh();

        // 2 + 1 + 0 + 1 + 1 + 1 + 1 = 7 of 15; the essay waits for the teacher.
        $this->assertSame(7.0, $attempt->score);
        $this->assertSame(15.0, $attempt->max_score);
        $this->assertTrue($attempt->needs_grading);

        // A second attempt is refused.
        $this->actingAs($student)->post(route('exams.start', $exam))->assertSessionHasErrors('exam');
        // Answers after submitting are refused.
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $q['short']->id, 'response' => ['text' => 'x']])->assertStatus(409);
    }

    public function test_the_teacher_marks_the_essay_and_the_total_follows(): void
    {
        $teacher = $this->examiner();
        $exam = $this->buildExam($teacher);
        $student = User::factory()->create();
        $essay = $exam->questions()->where('type', 'essay')->first();

        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::where('user_id', $student->id)->first();
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $essay->id, 'response' => ['text' => 'جواب']]);
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));

        $answer = $attempt->answers()->where('exam_question_id', $essay->id)->first();
        $this->actingAs($teacher)->put(route('exams.manage.answers.grade', $answer), ['points' => 4, 'comment' => 'جيد'])->assertSessionHasNoErrors();
        $this->actingAs($teacher)->put(route('exams.manage.answers.grade', $answer), ['points' => 9])->assertSessionHasErrors('points');

        $attempt->refresh();
        $this->assertSame(4.0, $attempt->score);
        $this->assertFalse($attempt->needs_grading);

        $this->actingAs($teacher)->get(route('exams.manage.results', $exam))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Exams/Manage/Results')->where('summary.submitted', 1));
        $this->actingAs($teacher)->get(route('exams.manage.export', $exam))->assertOk();
    }

    public function test_time_runs_out_on_the_server_and_overrides_add_minutes(): void
    {
        $teacher = $this->examiner();
        $exam = $this->buildExam($teacher);
        $student = User::factory()->create();

        $this->actingAs($teacher)->post(route('exams.manage.overrides.store', $exam), ['email' => $student->email, 'extra_minutes' => 15])->assertSessionHasNoErrors();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::where('user_id', $student->id)->first();
        $this->assertEqualsWithDelta(45 * 60, $attempt->secondsLeft(), 5);

        $this->travel(46)->minutes();
        $short = $exam->questions()->where('type', 'short')->first();
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $short->id, 'response' => ['text' => 'بغداد']])->assertStatus(409);
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertRedirect(route('exams.attempts.result', $attempt));
        $this->assertNotNull($attempt->fresh()->submitted_at);
    }

    public function test_students_cannot_manage_and_cannot_open_other_attempts(): void
    {
        $exam = $this->buildExam($this->examiner());
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($a)->get(route('exams.manage.index'))->assertForbidden();
        $this->actingAs($a)->get(route('exams.manage.edit', $exam))->assertForbidden();

        $this->actingAs($a)->post(route('exams.start', $exam));
        $attempt = Attempt::where('user_id', $a->id)->first();
        $this->actingAs($b)->get(route('exams.attempts.take', $attempt))->assertNotFound();
        $this->actingAs($b)->get(route('exams.attempts.result', $attempt))->assertNotFound();
    }

    public function test_unpublished_and_password_protected_exams(): void
    {
        $teacher = $this->examiner();
        $exam = $this->buildExam($teacher, ['is_published' => false]);
        $student = User::factory()->create();
        $this->actingAs($student)->get(route('exams.show', $exam))->assertNotFound();

        $exam->update(['is_published' => true, 'password' => 'sesame']);
        $this->actingAs($student)->post(route('exams.start', $exam), ['password' => 'nope'])->assertSessionHasErrors('password');
        $this->actingAs($student)->post(route('exams.start', $exam), ['password' => 'sesame'])->assertRedirect();
        $this->assertSame(1, Attempt::where('user_id', $student->id)->count());
    }

    public function test_grader_reads_numbers_and_arabic_spelling(): void
    {
        $this->assertSame(12.5, Grader::toNumber('١٢٫٥'));
        $this->assertSame(12.5, Grader::toNumber('12,5'));
        $this->assertNull(Grader::toNumber('abc'));
        $this->assertSame(Grader::normalize('مدرسة أحمد'), Grader::normalize('مدرسه احمد'));
        $this->assertContains(Question::ESSAY, Question::TYPES);
    }
}
