<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReviewPhotosSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private function payload(array $settings = [], string $body = 'رمز الماء؟'): array
    {
        return $settings + [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'duration_minutes' => 30,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true, 'pass_mark' => 50,
            'questions' => collect(range(1, 5))->map(fn ($i) => ['type' => 'single', 'points' => 1, 'body' => ['ar' => $body.$i], 'choices' => [
                ['body' => ['ar' => 'H2O'], 'is_correct' => true], ['body' => ['ar' => 'CO2'], 'is_correct' => false], ['body' => ['ar' => 'O2'], 'is_correct' => false],
            ]])->all(),
        ];
    }

    private function exam(array $settings = []): Exam
    {
        $this->teacher ??= User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $this->payload($settings));

        return $exam->fresh();
    }

    private function picture(): string
    {
        $img = imagecreatetruecolor(600, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    public function test_an_exam_with_a_reviewer_is_published_only_after_approval(): void
    {
        $this->teacher = User::factory()->create(['is_examiner' => true]);
        $reviewer = User::factory()->create(['is_examiner' => true, 'email' => 'rev@uni.test']);
        $student = User::factory()->create(['email' => 'st@uni.test']);
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);

        // A student cannot review; the reviewer is set and the exam stays a draft.
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $this->payload(['reviewer_email' => 'st@uni.test']))->assertSessionHasErrors('reviewer_email');
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $this->payload(['reviewer_email' => 'rev@uni.test']))->assertSessionHasErrors('is_published');
        $exam->refresh();
        $this->assertSame([$reviewer->id, 'pending', false], [$exam->reviewer_id, $exam->review_status, $exam->is_published]);

        // The reviewer sees it waiting, reads the key, and returns it with a note.
        $this->actingAs($reviewer)->get(route('exams.manage.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('to_review', 1));
        $this->actingAs($reviewer)->get(route('exams.manage.print', ['exam' => $exam, 'key' => 1]))->assertOk();
        $this->assertContains($this->actingAs($reviewer)->get(route('exams.manage.edit', $exam))->status(), [403, 404]);
        $this->actingAs($reviewer)->post(route('exams.manage.review', $exam), ['decision' => 'return'])->assertSessionHasErrors('note');
        $this->actingAs($reviewer)->post(route('exams.manage.review', $exam), ['decision' => 'return', 'note' => 'السؤال الثالث مكرر'])->assertSessionHasNoErrors();
        $this->assertSame('returned', $exam->fresh()->review_status);
        $this->actingAs($student)->post(route('exams.manage.review', $exam), ['decision' => 'approve'])->assertForbidden();

        // Approved: it can be published, until its questions change again.
        $this->actingAs($reviewer)->post(route('exams.manage.review', $exam), ['decision' => 'approve']);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $this->payload(['reviewer_email' => 'rev@uni.test']))->assertSessionHasNoErrors();
        $this->assertTrue($exam->fresh()->is_published);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $this->payload(['reviewer_email' => 'rev@uni.test'], 'سؤال آخر'))->assertSessionHasErrors('is_published');
        $this->assertSame(['pending', false], [$exam->fresh()->review_status, $exam->fresh()->is_published]);

        // Without a reviewer, as before.
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $this->payload(['reviewer_email' => '']))->assertSessionHasNoErrors();
        $this->assertTrue($exam->fresh()->is_published);
        $this->assertNull($exam->fresh()->reviewer_id);
    }

    public function test_official_photos_reach_the_invigilator_only(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $ali = User::factory()->create(['student_number' => '2024001']);
        $sara = User::factory()->create(['email' => 'sara@uni.test']);

        $this->actingAs($admin)->post(route('exams.manage.students.photo', $ali), ['photo' => UploadedFile::fake()->createWithContent('a.jpg', $this->picture())])->assertOk();
        $path = $ali->fresh()->photo_path;
        Storage::disk('local')->assertExists($path);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([300, 400], [$w, $h]);

        // A ZIP named by number or e-mail.
        $zipPath = tempnam(sys_get_temp_dir(), 'ph');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFromString('sara@uni.test.jpg', $this->picture());
        $zip->addFromString('9999999.jpg', $this->picture());
        $zip->addFromString('notes.txt', 'x');
        $zip->close();
        $this->actingAs($admin)->post(route('exams.manage.students.photos'), ['file' => new UploadedFile($zipPath, 'photos.zip', 'application/zip', null, true)])
            ->assertOk()->assertJson(['saved' => 1, 'unknown' => ['9999999.jpg']]);
        $this->assertNotNull($sara->fresh()->photo_path);

        // The monitor shows it; students and the public cannot open it, nor change it.
        $exam = $this->exam();
        $this->actingAs($ali)->post(route('exams.start', $exam));
        $row = collect($this->actingAs($this->teacher)->getJson(route('exams.monitor.data', $exam))->json('rows'))->firstWhere('user.id', $ali->id);
        $this->assertStringContainsString('/photos/'.$ali->id, $row['user']['photo']);
        $this->assertSame('2024001', $row['user']['number']);
        $this->actingAs($this->teacher)->get($row['user']['photo'])->assertOk();
        $this->actingAs($ali)->get($row['user']['photo'])->assertForbidden();
        $this->actingAs($ali)->post(route('exams.manage.students.photo', $ali), ['remove' => true])->assertForbidden();
        $this->assertArrayNotHasKey('photo_path', $ali->fresh()->toArray());

        $this->actingAs($admin)->post(route('exams.manage.students.photo', $ali), ['remove' => true])->assertJson(['photo' => null]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_the_grade_sheet_and_the_health_panel(): void
    {
        $exam = $this->exam();
        [$a, $b, $c] = User::factory()->count(3)->create();
        $a->forceFill(['student_number' => 'A-1'])->save();
        $right = $exam->questions()->with('choices')->get();
        foreach ([[$a, 5], [$b, 1]] as [$u, $n]) {
            $this->actingAs($u)->post(route('exams.start', $exam));
            $attempt = Attempt::where('user_id', $u->id)->first();
            foreach ($right->take($n) as $q) {
                $this->actingAs($u)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $q->id, 'response' => ['choice' => $q->choices->firstWhere('is_correct', true)->id]]);
            }
            $this->actingAs($u)->post(route('exams.attempts.submit', $attempt));
        }
        \Illuminate\Support\Facades\DB::table('exam_attendance')->insert(['exam_id' => $exam->id, 'user_id' => $c->id, 'present' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->teacher)->get(route('exams.manage.gradesheet', $exam))->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Exams/Manage/GradeSheet')->has('rows', 3)->where('counts.passed', 1)->where('counts.failed', 1)->where('counts.absent', 1)
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('number', 'A-1')['score'] == 5));
        $this->actingAs($a)->get(route('exams.manage.gradesheet', $exam))->assertForbidden();

        $health = $this->actingAs($this->teacher)->getJson(route('exams.monitor.health'))->assertOk()->json();
        $this->assertTrue($health['database']);
        $this->assertArrayHasKey('active', $health);
        $this->actingAs($a)->getJson(route('exams.monitor.health'))->assertForbidden();
    }

    public function test_a_printed_version_has_the_order_of_that_version_on_the_computers(): void
    {
        $exam = $this->exam(['variants' => 2]);
        [$a, $b] = User::factory()->count(2)->create();
        $this->actingAs($a)->post(route('exams.start', $exam));
        $this->actingAs($b)->post(route('exams.start', $exam));
        $second = Attempt::where('variant', 2)->firstOrFail();

        $printed = $this->actingAs($this->teacher)->get(route('exams.manage.print', ['exam' => $exam, 'variant' => 2]))->viewData('page')['props'];
        $this->assertSame(2, $printed['exam']['variant']);
        $this->assertSame($second->question_order, array_column($printed['questions'], 'id'));
        $onScreen = $this->actingAs(User::find($second->user_id))->get(route('exams.attempts.take', $second))->viewData('page')['props']['questions'][0]['choices'];
        $this->assertSame(array_column($onScreen, 'body'), array_column($printed['questions'][0]['choices'], 'body'));
    }
}
