<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** A picture for any question and any choice, kept through export and import. */
class PicturesTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = User::factory()->create(['is_examiner' => true]);
    }

    private function picture(string $name, int $w = 40): string
    {
        return $this->actingAs($this->teacher)->post(route('exams.manage.images.store'), ['image' => UploadedFile::fake()->image($name, $w, 30)])->json('image');
    }

    private function exam(): Exam
    {
        [$q, $a, $b] = [$this->picture('q.png', 50), $this->picture('a.png', 41), $this->picture('b.png', 42)];
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان الصور'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان الصور'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'show_answers' => true, 'is_published' => true,
            'questions' => [
                // A picture alone is enough, for the question and for its choices.
                ['type' => 'single', 'points' => 1, 'body' => [], 'image' => $q, 'choices' => [
                    ['body' => [], 'image' => $a, 'is_correct' => true], ['body' => ['ar' => 'لا شيء'], 'image' => $b], ['body' => ['ar' => 'غير ذلك']],
                ]],
                ['type' => 'essay', 'points' => 2, 'body' => ['ar' => 'صف الصورة'], 'image' => $q],
                ['type' => 'short', 'points' => 1, 'body' => ['ar' => 'بلا صورة'], 'image' => 'not-a-picture.png', 'settings' => ['answers' => ['x']]],
            ],
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_questions_and_choices_keep_their_pictures_for_the_student(): void
    {
        $exam = $this->exam();
        [$single, $essay, $short] = $exam->questions()->with('choices')->get()->all();
        $this->assertNotNull($single->image);
        $this->assertSame($single->image, $essay->image);
        $this->assertNull($short->image);
        $this->assertNotNull($single->choices[0]->image);
        $this->assertSame([], $single->choices[0]->body ?? []);
        $this->assertNull($single->choices[2]->image);

        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('questions.0.picture', route('exams.image', $single->image))
            ->where('questions.1.picture', route('exams.image', $essay->image))
            ->where('questions.2.picture', null)
            ->has('questions.0.choices', 3));
        $this->actingAs($this->teacher)->get(route('exams.manage.edit', $exam))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('exam.questions.0.image', $single->image)->where('exam.questions.0.choices.0.image', $single->choices[0]->image));
    }

    public function test_pictures_travel_in_the_exam_file_and_the_questions_zip(): void
    {
        $exam = $this->exam();
        $single = $exam->questions()->with('choices')->first();

        // The exam file: the pictures go with it.
        $package = json_decode($this->actingAs($this->teacher)->get(route('exams.manage.package', $exam))->streamedContent(), true);
        $this->assertArrayHasKey($single->image, $package['media']);
        $this->assertArrayHasKey($single->choices[0]->image, $package['media']);
        $file = UploadedFile::fake()->createWithContent('exam.examnest.json', json_encode($package));
        $this->actingAs($this->teacher)->post(route('exams.manage.package.import'), ['file' => $file])->assertSessionHasNoErrors();
        $copy = Exam::latest('id')->first();
        $this->assertNotSame($exam->id, $copy->id);
        $this->assertSame($single->image, $copy->questions()->first()->image);
        $this->assertSame($single->choices[0]->image, $copy->questions()->first()->choices()->first()->image);

        // The questions as a ZIP (sheet + pictures), imported into another exam.
        $response = $this->actingAs($this->teacher)->get(route('exams.manage.questions.export', $exam))->assertOk();
        $zipPath = $response->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath));
        $csv = (string) $zip->getFromName('questions.csv');
        $this->assertStringContainsString('صورة الخيار 1', $csv);
        $this->assertNotFalse($zip->getFromName('pictures/1.png'));
        $zip->close();

        $other = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'آخر'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->post(route('exams.manage.import', $other), ['file' => new UploadedFile($zipPath, 'questions.zip', 'application/zip', null, true)])
            ->assertSessionHasNoErrors();
        $imported = $other->questions()->with('choices')->get();
        $this->assertCount(3, $imported);
        $this->assertSame($single->image, $imported[0]->image);
        $this->assertSame($single->choices[0]->image, $imported[0]->choices[0]->image);
        $this->assertTrue((bool) $imported[0]->choices[0]->is_correct);
        $this->assertSame($single->choices[1]->image, $imported[0]->choices[1]->image);
        $this->assertSame('صف الصورة', $imported[1]->body['ar']);
    }

    public function test_a_sheet_naming_a_missing_picture_is_reported(): void
    {
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'آخر'], 'languages' => ['ar']]);
        $path = tempnam(sys_get_temp_dir(), 'z').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('q.csv', "\xEF\xBB\xBFالنوع,السؤال,الخيار 1,الخيار 2,الإجابة,الصورة\nاختيار,سؤال أول,نعم,لا,1,\nاختيار,سؤال ثانٍ,نعم,لا,1,missing.png\n");
        $zip->close();
        $this->actingAs($this->teacher)->post(route('exams.manage.import', $exam), ['file' => new UploadedFile($path, 'q.zip', 'application/zip', null, true)])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'missing.png'));
        $this->assertSame(1, $exam->questions()->count());
    }
}
