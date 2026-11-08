<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Answer;
use App\Models\Exams\Appeal;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MarkingExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private function exam(array $settings = [], ?array $questions = null): Exam
    {
        $this->teacher ??= User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $settings + [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'show_answers' => true, 'is_published' => true,
            'questions' => $questions ?? [['type' => 'essay', 'points' => 10, 'body' => ['ar' => 'اشرح'], 'settings' => ['rubric' => [['name' => 'الفكرة', 'max' => 6], ['name' => 'اللغة', 'max' => 4]]]]],
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    private function sit(Exam $exam, User $student, array $answers): Attempt
    {
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::where('user_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();
        foreach ($answers as $qid => $response) {
            $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $qid, 'response' => $response]);
        }
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));

        return $attempt->fresh();
    }

    public function test_marking_by_rubric_without_names(): void
    {
        $exam = $this->exam(['anonymous_marking' => true]);
        $this->assertEquals([['name' => 'الفكرة', 'max' => 6.0], ['name' => 'اللغة', 'max' => 4.0]], $exam->questions()->first()->settings['rubric']);
        $student = User::factory()->create(['name' => 'علي حسن']);
        $attempt = $this->sit($exam, $student, [$exam->questions()->value('id') => ['text' => 'الأكسدة فقدان إلكترونات']]);
        $answer = Answer::firstOrFail();

        $this->actingAs($this->teacher)->get(route('exams.manage.results', $exam))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('attempts.0.user.name', fn ($n) => str_starts_with($n, 'ورقة ') && $n !== 'علي حسن')
                ->where('attempts.0.user.email', null)->has('attempts.0.essays.0.rubric', 2));
        $this->actingAs($this->teacher)->get(route('exams.manage.results', ['exam' => $exam, 'names' => 1]))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('attempts.0.user.name', 'علي حسن'));

        $this->actingAs($this->teacher)->put(route('exams.manage.answers.grade', $answer), ['rubric' => [7, 3]])->assertSessionHasErrors('rubric');
        $this->actingAs($this->teacher)->put(route('exams.manage.answers.grade', $answer), ['rubric' => [5, 3.5]])->assertSessionHasNoErrors();
        $this->assertEquals(8.5, $answer->fresh()->points);
        $this->assertEquals([5, 3.5], $answer->fresh()->rubric);
        $this->assertEquals(8.5, $attempt->fresh()->score);

        // A rubric must add up to the question's points.
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'grading_method' => 'highest', 'show_results' => 'after_submit',
            'questions' => [['type' => 'essay', 'points' => 5, 'body' => ['ar' => 'اشرح'], 'settings' => ['rubric' => [['name' => 'أ', 'max' => 2]]]]],
        ])->assertSessionHasErrors('questions.0.settings');
    }

    public function test_appeals_and_results_from_a_date(): void
    {
        $exam = $this->exam(['appeals_open' => true, 'show_results' => 'at_date', 'results_at' => now()->addDay()->format('Y-m-d H:i')],
            [['type' => 'short', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد']]]]);
        $qid = $exam->questions()->value('id');
        $student = User::factory()->create();
        $attempt = $this->sit($exam, $student, [$qid => ['text' => 'بغداد العاصمة']]);

        // Before the date: no result, no appeal.
        $this->actingAs($student)->get(route('exams.attempts.result', $attempt))->assertInertia(fn (AssertableInertia $p) => $p->where('visible', false)->where('appeals_open', false));
        $this->actingAs($student)->post(route('exams.attempts.appeals.store', $attempt), ['question_id' => $qid, 'body' => 'إجابتي صحيحة'])->assertForbidden();

        $this->travel(2)->days();
        $this->actingAs($student)->get(route('exams.attempts.result', $attempt))->assertInertia(fn (AssertableInertia $p) => $p->where('visible', true)->where('appeals_open', true));
        $this->actingAs($student)->post(route('exams.attempts.appeals.store', $attempt), ['question_id' => $qid, 'body' => 'إجابتي صحيحة لأن بغداد هي العاصمة'])->assertSessionHasNoErrors();
        $this->actingAs($student)->post(route('exams.attempts.appeals.store', $attempt), ['question_id' => $qid, 'body' => 'مرة ثانية'])->assertSessionHasErrors('appeal');

        $appeal = Appeal::firstOrFail();
        $this->actingAs($this->teacher)->get(route('exams.manage.results', $exam))->assertInertia(fn (AssertableInertia $p) => $p->has('appeals', 1)->where('appeals.0.status', 'open'));
        $this->actingAs($this->teacher)->put(route('exams.manage.appeals.reply', $appeal), ['status' => 'accepted', 'reply' => 'صحيح، عُدّلت الدرجة.'])->assertSessionHasNoErrors();
        $this->actingAs($student)->get(route('exams.attempts.result', $attempt))->assertInertia(fn (AssertableInertia $p) => $p->where('appeals.0.status', 'accepted'));
        $this->actingAs(User::factory()->create())->put(route('exams.manage.appeals.reply', $appeal), ['status' => 'rejected'])->assertForbidden();
    }

    public function test_similar_answers_and_the_gradebook(): void
    {
        $exam = $this->exam([], [
            ['type' => 'essay', 'points' => 5, 'body' => ['ar' => 'اشرح الأكسدة']],
            ...collect(range(1, 3))->map(fn ($n) => ['type' => 'single', 'points' => 1, 'body' => ['ar' => "س{$n}"], 'choices' => [
                ['body' => ['ar' => 'صح'], 'is_correct' => true], ['body' => ['ar' => 'خطأ أ'], 'is_correct' => false], ['body' => ['ar' => 'خطأ ب'], 'is_correct' => false],
            ]])->all(),
        ]);
        $questions = $exam->questions()->with('choices')->get();
        $essay = $questions->first();
        $text = 'الأكسدة هي فقدان الإلكترونات من الذرة أو الأيون وتحدث دائماً مع الاختزال في تفاعل واحد يسمى تفاعل الأكسدة والاختزال في الكيمياء';
        [$a, $b, $c] = User::factory()->count(3)->create();
        $wrong = fn () => $questions->slice(1)->mapWithKeys(fn ($q) => [$q->id => ['choice' => $q->choices[1]->id]])->all();
        $this->sit($exam, $a, [$essay->id => ['text' => $text]] + $wrong());
        $this->sit($exam, $b, [$essay->id => ['text' => $text.' وهذا مهم']] + $wrong());
        $this->sit($exam, $c, [$essay->id => ['text' => 'إجابة مختلفة تماماً عن الموضوع كله لا تشبه غيرها في أي عبارة من العبارات المكتوبة هنا أبداً']]);

        $pairs = collect($this->actingAs($this->teacher)->getJson(route('exams.manage.similarity', $exam))->assertOk()->json());
        $this->assertTrue($pairs->contains(fn ($p) => $p['kind'] === 'essay' && $p['score'] >= 80 && collect([$p['a_name'], $p['b_name']])->sort()->values()->all() === collect([$a->name, $b->name])->sort()->values()->all()));
        $this->assertTrue($pairs->contains(fn ($p) => $p['kind'] === 'choices' && $p['score'] === 3));
        $this->assertFalse($pairs->contains(fn ($p) => in_array($c->name, [$p['a_name'], $p['b_name']], true)));

        // The group's gradebook (and as a sheet).
        $group = Group::create(['user_id' => $this->teacher->id, 'name' => 'أ']);
        $group->members()->attach([$a->id, $b->id]);
        $exam->groups()->sync([$group->id]);
        $this->actingAs($this->teacher)->get(route('exams.manage.groups.gradebook', $group))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('columns', 1)->has('rows', 2)->where('rows.0.marks.0.score', fn ($s) => $s == 0));
        $csv = $this->actingAs($this->teacher)->get(route('exams.manage.groups.gradebook', ['group' => $group, 'csv' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString($a->name, $csv);
    }
}
