<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Answer;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_exam_goes_to_another_server_and_its_results_come_back(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->artisan('exams:demo')->assertSuccessful();
        $exam = Exam::firstOrFail();
        $admin = User::where('email', 'admin@exams.test')->firstOrFail();

        // The package: every question, the random slot's pool, the pictures.
        $package = json_decode($this->actingAs($admin)->get(route('exams.manage.package', $exam))->assertOk()->streamedContent(), true);
        $this->assertSame('examnest-exam', $package['format']);
        $this->assertCount($exam->questions()->count(), $package['questions']);
        $this->assertNotEmpty($package['media']);
        $this->assertCount(4, collect($package['questions'])->firstWhere('type', 'random')['pool']);

        // «Another server»: imported as a new, unpublished exam with the same key.
        $this->actingAs($admin)->post(route('exams.manage.package.import'), ['file' => UploadedFile::fake()->createWithContent('exam.examnest.json', json_encode($package))])->assertRedirect();
        $copy = Exam::latest('id')->firstOrFail();
        $this->assertNotSame($exam->id, $copy->id);
        $this->assertFalse($copy->is_published);
        $this->assertSame($exam->fresh()->transfer_key, $copy->transfer_key);
        $this->assertSame($exam->questions()->pluck('type')->all(), $copy->questions()->pluck('type')->all());
        $slot = $copy->questions()->where('type', 'random')->first();
        $this->assertNotSame($exam->questions()->where('type', 'random')->first()->settings['category_id'], $slot->settings['category_id']);
        $copy->update(['is_published' => true]);

        // A student sits the copy; its results come back to the original exam.
        $student = User::factory()->create(['email' => 'hall@x.test', 'name' => 'طالب القاعة']);
        $this->actingAs($student)->post(route('exams.start', $copy));
        $attempt = Attempt::where('exam_id', $copy->id)->firstOrFail();
        $short = $copy->questions()->where('type', 'short')->first();
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $short->id, 'response' => ['text' => 'بغداد']]);
        $drawnId = (int) array_key_first($attempt->drawn);
        $right = Question::with('choices')->find($drawnId)->choices->firstWhere('is_correct', true)->id;
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $drawnId, 'response' => ['choice' => $right]]);
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));
        $hallScore = $attempt->fresh()->score;

        $bundle = $this->actingAs($admin)->get(route('exams.manage.results-bundle', $copy))->streamedContent();
        $student->delete();   // the central server has never seen this student
        $this->actingAs($admin)->post(route('exams.manage.results-bundle.import', $exam), ['file' => UploadedFile::fake()->createWithContent('r.json', $bundle)])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 محاولة'));
        $back = Attempt::where('exam_id', $exam->id)->firstOrFail();
        $this->assertSame('hall@x.test', $back->user->email);
        $this->assertEquals($hallScore, $back->score);
        $this->assertSame('بغداد', Answer::where('exam_attempt_id', $back->id)->where('exam_question_id', $exam->questions()->where('type', 'short')->value('id'))->first()->response['text']);
        // Twice is harmless; another exam's results are refused.
        $this->actingAs($admin)->post(route('exams.manage.results-bundle.import', $exam), ['file' => UploadedFile::fake()->createWithContent('r.json', $bundle)]);
        $this->assertSame(1, Attempt::where('exam_id', $exam->id)->count());
        $other = Exam::create(['user_id' => $admin->id, 'title' => ['ar' => 'آخر'], 'languages' => ['ar'], 'transfer_key' => 'other']);
        $this->actingAs($admin)->post(route('exams.manage.results-bundle.import', $other), ['file' => UploadedFile::fake()->createWithContent('r.json', $bundle)])->assertSessionHasErrors('file');
    }

    public function test_qti_out_and_in(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان QTI'], 'languages' => ['ar']]);
        $this->actingAs($teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان QTI'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'grading_method' => 'highest', 'show_results' => 'after_submit',
            'questions' => [
                ['type' => 'single', 'points' => 2, 'body' => ['ar' => 'رمز الماء؟'], 'choices' => [['body' => ['ar' => 'H2O'], 'is_correct' => true], ['body' => ['ar' => 'CO2'], 'is_correct' => false]]],
                ['type' => 'multiple', 'points' => 1, 'body' => ['ar' => 'غازات نبيلة'], 'choices' => [['body' => ['ar' => 'هيليوم'], 'is_correct' => true], ['body' => ['ar' => 'نيون'], 'is_correct' => true], ['body' => ['ar' => 'حديد'], 'is_correct' => false]]],
                ['type' => 'short', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد', 'Baghdad']]],
                ['type' => 'numeric', 'points' => 1, 'body' => ['ar' => 'π'], 'settings' => ['answer' => '3.14', 'tolerance' => '0']],
                ['type' => 'essay', 'points' => 5, 'body' => ['ar' => 'اشرح & قارن <بين>']],
                ['type' => 'ordering', 'points' => 1, 'body' => ['ar' => 'رتّب'], 'choices' => [['body' => ['ar' => 'أ']], ['body' => ['ar' => 'ب']]]],
            ],
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($teacher)->get(route('exams.manage.qti', $exam))->assertOk();
        $zipPath = $response->baseResponse->getFile()->getPathname();
        $copyPath = tempnam(sys_get_temp_dir(), 'q').'.zip';
        copy($zipPath, $copyPath);
        $zip = new \ZipArchive;
        $zip->open($copyPath);
        $this->assertNotFalse($zip->getFromName('imsmanifest.xml'));
        $this->assertStringContainsString('qti-choice-interaction', $zip->getFromName('items/q1.xml'));
        $this->assertFalse($zip->getFromName('items/q6.xml'));   // ordering has no common QTI form here
        $zip->close();

        $this->actingAs($teacher)->post(route('exams.manage.package.import'), ['file' => new UploadedFile($copyPath, 'exam-qti3.zip', 'application/zip', null, true)])->assertRedirect();
        $copy = Exam::latest('id')->firstOrFail();
        $this->assertSame('امتحان QTI', $copy->title['ar']);
        $this->assertSame(['single', 'multiple', 'short', 'numeric', 'essay'], $copy->questions()->pluck('type')->all());
        [$single, $multiple, $short, $numeric, $essay] = $copy->questions()->with('choices')->get()->all();
        $this->assertSame([true, false], $single->choices->pluck('is_correct')->all());
        $this->assertEquals(2, $single->points);
        $this->assertSame([true, true, false], $multiple->choices->pluck('is_correct')->all());
        $this->assertEqualsCanonicalizing(['بغداد', 'Baghdad'], $short->settings['answers']);
        $this->assertSame('3.14', $numeric->settings['answer']);
        $this->assertSame('اشرح & قارن <بين>', $essay->body['ar']);
    }

    public function test_ai_suggestions_on_the_hosted_site_only(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'is_published' => true]);
        $q = Question::create(['exam_id' => $exam->id, 'type' => 'essay', 'body' => ['ar' => 'اشرح'], 'points' => 10, 'settings' => ['rubric' => [['name' => 'الفكرة', 'max' => 6], ['name' => 'اللغة', 'max' => 4]]]]);
        $student = User::factory()->create(['name' => 'اسم سري']);
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $q->id, 'response' => ['text' => 'الأكسدة فقدان إلكترونات']]);
        $answer = Answer::firstOrFail();

        $this->actingAs($teacher)->postJson(route('exams.manage.answers.suggest', $answer))->assertNotFound();   // no key yet
        Setting::setMany(['ai_api_key' => Crypt::encryptString('sk-test'), 'ai_model' => 'claude-sonnet-5-5']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'هذا اقتراحي: {"points": 7, "rubric": [5, 9], "reason": "فكرة صحيحة ناقصة"}']]])]);

        $this->actingAs($teacher)->postJson(route('exams.manage.answers.suggest', $answer))->assertOk()
            ->assertJson(['rubric' => [5, 4], 'points' => 9, 'reason' => 'فكرة صحيحة ناقصة']);
        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'sk-test') && ! str_contains($r['messages'][0]['content'], 'اسم سري') && str_contains($r['messages'][0]['content'], 'فقدان إلكترونات'));
        $this->assertNull($answer->fresh()->points);   // only a suggestion

        config(['offline.edition' => true, 'offline.activation' => false]);
        $this->actingAs($teacher)->postJson(route('exams.manage.answers.suggest', $answer))->assertNotFound();
        auth()->logout();
        $this->get(route('login'))->assertInertia(fn (AssertableInertia $p) => $p->where('oauth', []));
    }
}
