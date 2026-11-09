<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\User;
use App\Services\Exams\AutoMultipleChoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoMultipleChoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private function exam(): Exam
    {
        $this->teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'كيمياء عامة'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true, 'shuffle_choices' => true,
            'questions' => [
                ['type' => 'single', 'points' => 2, 'body' => ['ar' => "رمز\nالماء؟"], 'choices' => [['body' => ['ar' => 'H2O'], 'is_correct' => true], ['body' => ['ar' => 'CO2'], 'is_correct' => false], ['body' => ['ar' => 'NaCl'], 'is_correct' => false]]],
                ['type' => 'multiple', 'points' => 2, 'body' => ['ar' => 'الغازات النبيلة'], 'choices' => [['body' => ['ar' => 'هيليوم'], 'is_correct' => true], ['body' => ['ar' => 'نيون'], 'is_correct' => true], ['body' => ['ar' => 'حديد'], 'is_correct' => false]]],
                ['type' => 'true_false', 'points' => 1, 'body' => ['ar' => 'الماء مركب'], 'choices' => [['body' => ['ar' => 'صح'], 'is_correct' => true], ['body' => ['ar' => 'خطأ'], 'is_correct' => false]]],
                ['type' => 'essay', 'points' => 5, 'body' => ['ar' => 'اشرح']],
            ],
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_the_exam_goes_out_as_an_amc_project(): void
    {
        $exam = $this->exam();
        $student = User::factory()->create(['name' => 'علي_حسن']);
        DB::table('exam_attendance')->insert(['exam_id' => $exam->id, 'user_id' => $student->id, 'created_at' => now(), 'updated_at' => now()]);
        $amc = app(AutoMultipleChoice::class);
        [$single, $multi, $tf] = $exam->questions()->orderBy('position')->get()->all();

        $src = $amc->source($exam);
        $this->assertStringContainsString('Lang: AR', $src);
        $this->assertStringContainsString('PreAssociationKey: id', $src);
        $this->assertStringContainsString("ArabicFont: Amiri\nFont: Linux Libertine O\nLaTeX: 1", $src);
        $this->assertStringContainsString("*[id=q{$single->id}]{b=2} رمز الماء؟\n+ {\\beginL\\rmfamily H2O\\endL}\n- {\\beginL\\rmfamily CO2\\endL}\n- {\\beginL\\rmfamily NaCl\\endL}", $src);
        $this->assertStringContainsString("**[id=q{$multi->id}]{haut=2} الغازات النبيلة\n+ هيليوم\n+ نيون\n- حديد", $src);
        $this->assertStringContainsString("*[id=q{$tf->id},ordered]{b=1} الماء مركب", $src);
        $this->assertStringNotContainsString('اشرح', $src);
        $this->assertSame("id,name\nS{$student->id},علي حسن\n", $amc->students($exam));

        $res = $this->actingAs($this->teacher)->get(route('exams.manage.amc', $exam))->assertOk();
        $zip = new \ZipArchive;
        $zip->open($res->baseResponse->getFile()->getPathname());
        $this->assertSame($src, $zip->getFromName('exam.txt'));
        $this->assertNotFalse($zip->getFromName('README-ar.txt'));
    }

    public function test_amc_results_come_back_as_marked_attempts(): void
    {
        $exam = $this->exam();
        [$a, $b] = User::factory()->count(2)->create();
        foreach ([$a, $b] as $u) {
            DB::table('exam_attendance')->insert(['exam_id' => $exam->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$single, $multi, $tf] = $exam->questions()->orderBy('position')->get()->all();

        // Ticked boxes as letters (and one as 0/1 per box), semicolon-separated like a French AMC.
        $csv = "\"copy\";\"key\";\"name\";\"Mark\";\"TICKED:q{$single->id}\";\"TICKED:q{$multi->id}\";\"TICKED:q{$tf->id}\"\n"
            ."\"1\";\"S{$a->id}\";\"أ\";\"20\";\"A\";\"AB\";\"1;0\"\n"
            ."\"2\";\"S{$b->id}\";\"ب\";\"5\";\"B\";\"A\";\"B\"\n"
            ."\"3\";\"S999999\";\"غريب\";\"0\";\"\";\"\";\"\"\n";
        $this->actingAs($this->teacher)->post(route('exams.manage.amc.import', $exam), ['file' => UploadedFile::fake()->createWithContent('results.csv', $csv)])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $ofA = Attempt::where('user_id', $a->id)->firstOrFail();
        $ofB = Attempt::where('user_id', $b->id)->firstOrFail();
        $this->assertEquals(5, $ofA->score);   // 2 + 2 + 1, marked here
        $this->assertEquals(1, $ofB->score);   // half of the multiple-answer question
        $this->assertEquals(10, $ofA->max_score);   // the essay still to mark by hand
        $this->assertSame([0, 1], AutoMultipleChoice::boxes('1;1;0', 3));
        $this->assertSame([0, 2], AutoMultipleChoice::boxes('ac', 3));

        // Without ticked boxes: AMC's own score for each question.
        $csv = "key,name,Mark,q{$single->id},q{$multi->id},q{$tf->id}\nS{$b->id},ب,3,2,0.5,1\n";
        $this->actingAs($this->teacher)->post(route('exams.manage.amc.import', $exam), ['file' => UploadedFile::fake()->createWithContent('r.csv', $csv)])->assertSessionHasNoErrors();
        $this->assertEquals(3.5, $ofB->fresh()->score);

        // A file from another exam's project.
        $this->actingAs($this->teacher)->post(route('exams.manage.amc.import', $exam), ['file' => UploadedFile::fake()->createWithContent('x.csv', "key,Mark\nS1,3\n")])
            ->assertSessionHasErrors('file');
    }
}
