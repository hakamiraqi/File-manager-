<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\BankCategory;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BankTest extends TestCase
{
    use RefreshDatabase;

    private function examiner(): User
    {
        return User::factory()->create(['is_examiner' => true]);
    }

    /** A bank category with $n single-choice questions; the first choice of each is right. */
    private function category(User $owner, int $n = 3, bool $shared = false): BankCategory
    {
        $category = BankCategory::create(['user_id' => $owner->id, 'name' => 'كيمياء', 'is_shared' => $shared]);
        for ($i = 1; $i <= $n; $i++) {
            $q = Question::create(['exam_id' => null, 'bank_category_id' => $category->id, 'type' => 'single', 'body' => ['ar' => "سؤال $i"], 'points' => 1]);
            Choice::create(['exam_question_id' => $q->id, 'body' => ['ar' => 'صحيح'], 'is_correct' => true, 'position' => 0]);
            Choice::create(['exam_question_id' => $q->id, 'body' => ['ar' => 'خطأ'], 'is_correct' => false, 'position' => 1]);
        }

        return $category;
    }

    private function exam(User $teacher, array $questions): Exam
    {
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => $questions,
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_a_teacher_keeps_questions_in_the_bank_and_copies_them_into_an_exam(): void
    {
        $teacher = $this->examiner();
        $this->actingAs($teacher)->post(route('exams.manage.bank.categories.store'), ['name' => 'فيزياء', 'is_shared' => false])->assertRedirect();
        $category = BankCategory::firstOrFail();

        $csv = "السؤال,الخيار 1,الخيار 2,الإجابة\nوحدة القوة,نيوتن,جول,أ\nوحدة الطاقة,واط,جول,ب\n";
        $this->actingAs($teacher)->post(route('exams.manage.bank.categories.import', $category), [
            'file' => UploadedFile::fake()->createWithContent('q.csv', $csv),
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $category->questions()->count());

        $this->actingAs($teacher)->get(route('exams.manage.bank', ['category' => $category->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Exams/Manage/Bank')->has('categories', 1)->has('questions', 2));

        // Bank → exam: a copy, so editing the exam leaves the bank alone.
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($teacher)->post(route('exams.manage.bank.to-exam', $exam), ['ids' => $category->questions()->pluck('id')->all()])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, $exam->questions()->count());
        $this->assertSame('جول', $exam->questions()->latest('id')->first()->choices()->where('is_correct', true)->first()->body['ar']);

        // Exam → bank.
        $this->actingAs($teacher)->post(route('exams.manage.bank.from-exam', $exam->questions()->first()), ['category_id' => $category->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $category->questions()->count());
        $this->assertSame(2, $exam->questions()->count());

        // Deleting the exam keeps the bank.
        $this->actingAs($teacher)->delete(route('exams.manage.destroy', $exam));
        $this->assertSame(3, $category->questions()->count());
    }

    public function test_each_student_draws_their_own_questions_and_is_marked_on_them(): void
    {
        $teacher = $this->examiner();
        $category = $this->category($teacher, 3);
        $exam = $this->exam($teacher, [
            ['type' => 'true_false', 'points' => 1, 'body' => ['ar' => 'ثابت'], 'choices' => [
                ['body' => ['ar' => 'صح'], 'is_correct' => true], ['body' => ['ar' => 'خطأ'], 'is_correct' => false],
            ]],
            ['type' => 'random', 'points' => 2, 'body' => [], 'settings' => ['category_id' => $category->id, 'count' => 2]],
        ]);
        $this->assertSame(5.0, $exam->maxScore());   // 1 + 2 × 2

        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam))->assertRedirect();
        $attempt = Attempt::where('user_id', $student->id)->firstOrFail();

        $drawn = array_map('intval', array_keys($attempt->drawn));
        $this->assertCount(2, array_unique($drawn));
        $this->assertEmpty(array_diff($drawn, $category->questions()->pluck('id')->all()));
        $this->assertCount(3, $attempt->question_order);

        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Exams/Take')->has('questions', 3)
                ->where('questions', fn ($qs) => collect($qs)->pluck('type')->doesntContain('random')));

        // Right on both drawn questions, wrong on the fixed one.
        foreach ($drawn as $id) {
            $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), [
                'question_id' => $id, 'response' => ['choice' => Choice::where('exam_question_id', $id)->where('is_correct', true)->value('id')],
            ])->assertOk();
        }
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));
        $attempt->refresh();
        $this->assertSame(4.0, $attempt->score);
        $this->assertSame(5.0, $attempt->max_score);

        // A question that was answered stays in the bank.
        $this->actingAs($teacher)->delete(route('exams.manage.bank.questions.destroy', $drawn[0]))->assertSessionHasErrors('question');
        $this->actingAs($teacher)->delete(route('exams.manage.bank.categories.destroy', $category))->assertSessionHasErrors('category');
        $this->assertSame(3, $category->questions()->count());
    }

    public function test_a_random_slot_needs_a_usable_category_and_enough_questions(): void
    {
        $owner = $this->examiner();
        $private = $this->category($owner, 2);
        $shared = $this->category($owner, 2, shared: true);
        $other = $this->examiner();
        $exam = Exam::create(['user_id' => $other->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $put = fn (int $cat, int $count) => $this->actingAs($other)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit',
            'questions' => [['type' => 'random', 'points' => 1, 'settings' => ['category_id' => $cat, 'count' => $count]]],
        ]);

        $put($private->id, 1)->assertSessionHasErrors('questions.0.settings');
        $put($shared->id, 3)->assertSessionHasErrors('questions.0.settings');
        $put($shared->id, 2)->assertSessionHasNoErrors();

        // Others see the shared category, not the private one, and cannot change it.
        $this->actingAs($other)->get(route('exams.manage.bank'))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('categories', 1)->where('categories.0.id', $shared->id)->where('categories.0.editable', false));
        $this->actingAs($other)->delete(route('exams.manage.bank.categories.destroy', $shared))->assertNotFound();
        $this->actingAs($other)->post(route('exams.manage.bank.from-exam', Question::where('exam_id', $exam->id)->first()), ['category_id' => $shared->id])
            ->assertNotFound();
    }

    public function test_students_have_no_bank(): void
    {
        $this->actingAs(User::factory()->create())->get(route('exams.manage.bank'))->assertForbidden();
    }
}
