<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Answer;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NewTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private string $image;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = User::factory()->create(['is_examiner' => true]);
        $this->image = $this->actingAs($this->teacher)
            ->post(route('exams.manage.images.store'), ['image' => UploadedFile::fake()->image('map.png', 400, 300)])
            ->assertOk()->json('image');
    }

    private function exam(array $questions): Exam
    {
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'show_answers' => true, 'is_published' => true,
            'questions' => $questions,
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    private function questions(): array
    {
        return [
            ['type' => 'classify', 'points' => 4, 'body' => ['ar' => 'صنّف'], 'choices' => [
                ['body' => ['ar' => 'تفاح'], 'match' => ['ar' => 'فاكهة']], ['body' => ['ar' => 'موز'], 'match' => ['ar' => 'فاكهة']],
                ['body' => ['ar' => 'جزر'], 'match' => ['ar' => 'خضار']], ['body' => ['ar' => 'خيار'], 'match' => ['ar' => 'خضار']],
            ]],
            ['type' => 'hotspot', 'points' => 2, 'body' => ['ar' => 'أين بغداد؟'],
                'settings' => ['image' => $this->image, 'image_url' => 'ignored', 'zones' => [['x' => 40, 'y' => 30, 'w' => 10, 'h' => 10]]]],
            ['type' => 'upload', 'points' => 5, 'body' => ['ar' => 'ارفع تقريرك'], 'settings' => ['max_mb' => 1, 'types' => '.PDF, docx']],
        ];
    }

    public function test_the_editor_checks_the_new_types(): void
    {
        $exam = $this->exam($this->questions());
        [$classify, $hotspot, $upload] = $exam->questions()->get()->all();
        $this->assertSame(['image' => $this->image, 'zones' => [['x' => 40.0, 'y' => 30.0, 'w' => 10.0, 'h' => 10.0]]], $hotspot->settings);
        $this->assertSame(['max_mb' => 1, 'types' => 'pdf,docx'], $upload->settings);
        $this->assertSame(11.0, $exam->maxScore());

        $put = fn (array $q) => $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'questions' => [$q],
        ]);
        $put(['type' => 'classify', 'body' => ['ar' => 'صنّف'], 'choices' => [
            ['body' => ['ar' => 'أ'], 'match' => ['ar' => 'واحدة']], ['body' => ['ar' => 'ب'], 'match' => ['ar' => 'واحدة']],
        ]])->assertSessionHasErrors('questions.0.choices');
        $put(['type' => 'hotspot', 'body' => ['ar' => 'أين؟'], 'settings' => ['image' => str_repeat('a', 64).'.png', 'zones' => [['x' => 1, 'y' => 1, 'w' => 5, 'h' => 5]]]])
            ->assertSessionHasErrors('questions.0.settings');
        $put(['type' => 'hotspot', 'body' => ['ar' => 'أين؟'], 'settings' => ['image' => $this->image, 'zones' => []]])
            ->assertSessionHasErrors('questions.0.settings');
    }

    public function test_a_student_answers_the_new_types_and_the_teacher_marks_the_file(): void
    {
        $exam = $this->exam($this->questions());
        [$classify, $hotspot, $upload] = $exam->questions()->with('choices')->get()->all();
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();

        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('questions.0.items', 4)->has('questions.0.targets', 2)->where('questions.0.choices', [])
                ->where('questions.1.image', route('exams.image', $this->image))->missing('questions.1.zones')
                ->where('questions.2.max_mb', 1)->where('questions.2.types', 'pdf,docx'));
        $this->actingAs($student)->get(route('exams.image', $this->image))->assertOk();

        // Classify: three of four right. Any choice of the category stands for it.
        [$apple, $banana, $carrot, $cucumber] = $classify->choices->all();
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $classify->id, 'response' => ['pairs' => [
            $apple->id => $banana->id, $banana->id => $banana->id, $carrot->id => $cucumber->id, $cucumber->id => $apple->id,
        ]]])->assertOk();
        // Hotspot: inside the zone.
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $hotspot->id, 'response' => ['x' => 45.5, 'y' => 39]])->assertOk();

        // Upload: the size, the type and the page cannot fake it.
        $send = fn ($file) => $this->actingAs($student)->post(route('exams.attempts.upload', $attempt), ['question_id' => $upload->id, 'file' => $file]);
        $send(UploadedFile::fake()->create('big.pdf', 2048))->assertSessionHasErrors('file');
        $send(UploadedFile::fake()->create('report.txt', 10))->assertSessionHasErrors('file');
        $this->actingAs($student)->postJson(route('exams.attempts.upload', $attempt), ['question_id' => $classify->id, 'file' => UploadedFile::fake()->create('x.pdf', 10)])->assertStatus(422);
        $send(UploadedFile::fake()->create('first.pdf', 10))->assertOk();
        $first = Answer::where('exam_question_id', $upload->id)->first()->response['file'];
        $send(UploadedFile::fake()->create('report.pdf', 20))->assertOk()->assertJson(['name' => 'report.pdf']);
        Storage::disk('local')->assertMissing($first);
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $upload->id, 'response' => ['file' => '../../.env', 'name' => 'x']])->assertOk();
        $answer = Answer::where('exam_question_id', $upload->id)->first();
        $this->assertSame('report.pdf', $answer->response['name']);
        Storage::disk('local')->assertExists($answer->response['file']);

        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));
        $attempt->refresh();
        $this->assertSame(5.0, $attempt->score);   // 3 of 4 × 4 + 2
        $this->assertTrue($attempt->needs_grading);

        $this->actingAs($student)->get(route('exams.attempts.result', $attempt))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('review.1.point.x', fn ($x) => $x == 45.5)->where('review.1.point.y', fn ($y) => $y == 39)->has('review.1.zones', 1)
                ->where('review.2.given', 'report.pdf'));

        // The teacher downloads it and marks it; another student cannot.
        $this->actingAs($this->teacher)->get(route('exams.manage.results', $exam))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('attempts.0.essays.0.file.name', 'report.pdf'));
        $this->actingAs($this->teacher)->get(route('exams.answers.file', $answer))->assertOk()->assertDownload('report.pdf');
        $this->actingAs(User::factory()->create())->get(route('exams.answers.file', $answer))->assertNotFound();
        $this->actingAs($this->teacher)->put(route('exams.manage.answers.grade', $answer), ['points' => 4]);
        $attempt->refresh();
        $this->assertSame(9.0, $attempt->score);
        $this->assertFalse($attempt->needs_grading);
    }

    public function test_a_click_outside_the_zone_scores_nothing(): void
    {
        $exam = $this->exam([$this->questions()[1]]);
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $exam->questions()->value('id'), 'response' => ['x' => 70, 'y' => 30]]);
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));
        $this->assertSame(0.0, $attempt->fresh()->score);
    }

    public function test_students_cannot_upload_pictures_for_questions(): void
    {
        $this->actingAs(User::factory()->create())->post(route('exams.manage.images.store'), ['image' => UploadedFile::fake()->image('a.png')])->assertForbidden();
    }
}
