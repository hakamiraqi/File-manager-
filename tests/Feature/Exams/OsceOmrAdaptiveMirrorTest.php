<?php

namespace Tests\Feature\Exams;

use App\Http\Controllers\Exams\OmrController;
use App\Models\Exams\Attempt;
use App\Models\Exams\BankCategory;
use App\Models\Exams\Exam;
use App\Models\Exams\OsceStation;
use App\Models\Exams\Question;
use App\Models\Setting;
use App\Models\User;
use App\Services\Exams\Mirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OsceOmrAdaptiveMirrorTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private function exam(array $settings = [], ?array $questions = null): Exam
    {
        $this->teacher ??= User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $settings + [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => $questions ?? [],
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_an_osce_is_marked_station_by_station(): void
    {
        $exam = $this->exam();
        $assessor = User::factory()->create(['is_invigilator' => true, 'email' => 'dr@uni.test']);
        $student = User::factory()->create();
        \Illuminate\Support\Facades\DB::table('exam_attendance')->insert(['exam_id' => $exam->id, 'user_id' => $student->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->teacher)->put(route('exams.manage.osce.stations', $exam), ['stations' => [
            ['name' => 'قياس الضغط', 'minutes' => 8, 'assessor_email' => 'dr@uni.test', 'checklist' => [['item' => 'غسل اليدين', 'points' => 2], ['item' => 'القراءة الصحيحة', 'points' => 3]]],
            ['name' => 'التاريخ المرضي', 'minutes' => 8, 'checklist' => [['item' => 'السؤال عن الحساسية', 'points' => 5]]],
        ]])->assertSessionHasNoErrors();
        $exam->refresh();
        $this->assertTrue($exam->is_osce);
        $this->assertEquals(10, $exam->maxScore());
        [$s1, $s2] = $exam->osceStations()->get()->all();

        // The student does not take it on a computer.
        $this->actingAs($student)->post(route('exams.start', $exam))->assertSessionHasErrors('exam');

        // The assessor sees only their station; marks are capped at each item's points.
        $this->actingAs($assessor)->get(route('exams.monitor.osce'))->assertInertia(fn (AssertableInertia $p) => $p->has('stations', 1));
        $this->actingAs($assessor)->get(route('exams.monitor.osce.station', $s2))->assertNotFound();
        $this->actingAs($assessor)->postJson(route('exams.monitor.osce.mark', [$s1, $student]), ['scores' => [2, 9], 'global_rating' => 4])->assertJson(['total' => 5]);
        $attempt = Attempt::where('exam_id', $exam->id)->where('user_id', $student->id)->firstOrFail();
        $this->assertEquals(5, $attempt->score);
        $this->assertTrue($attempt->needs_grading);

        $this->actingAs($this->teacher)->postJson(route('exams.monitor.osce.mark', [$s2, $student]), ['scores' => [2.5]])->assertOk();
        $attempt->refresh();
        $this->assertEquals(7.5, $attempt->score);
        $this->assertEquals(10, $attempt->max_score);
        $this->assertFalse($attempt->needs_grading);
        // Regrading leaves the stations' mark alone.
        $this->actingAs($this->teacher)->post(route('exams.manage.regrade', $exam));
        $this->assertEquals(7.5, $attempt->fresh()->score);
        $this->actingAs($this->teacher)->get(route('exams.manage.osce', $exam))->assertInertia(fn (AssertableInertia $p) => $p->has('candidates', 1)->where('max', fn ($m) => $m == 10));
    }

    public function test_a_scanned_bubble_sheet_becomes_a_marked_attempt(): void
    {
        $choices = [['body' => ['ar' => 'أ'], 'is_correct' => true], ['body' => ['ar' => 'ب'], 'is_correct' => false], ['body' => ['ar' => 'ج'], 'is_correct' => false]];
        $exam = $this->exam([], [
            ['type' => 'single', 'points' => 1, 'body' => ['ar' => 'س1'], 'choices' => $choices],
            ['type' => 'single', 'points' => 1, 'body' => ['ar' => 'س2'], 'choices' => $choices],
            ['type' => 'essay', 'points' => 5, 'body' => ['ar' => 'اشرح']],
        ]);
        $student = User::factory()->create();
        \Illuminate\Support\Facades\DB::table('exam_attendance')->insert(['exam_id' => $exam->id, 'user_id' => $student->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->teacher)->get(route('exams.manage.omr', $exam))->assertInertia(fn (AssertableInertia $p) => $p
            ->has('questions', 2)->where('skipped', 1)->where('students.0.code', OmrController::code($exam, $student->id)));
        [$q1, $q2] = $exam->questions()->orderBy('position')->get()->all();

        $code = OmrController::code($exam, $student->id);
        $this->actingAs($this->teacher)->postJson(route('exams.manage.omr.store', $exam), ['code' => $code, 'answers' => [$q1->id => [0], $q2->id => [2]]])
            ->assertJson(['ok' => true, 'score' => 1]);
        // Read again (a corrected bubble): the same attempt, the new answer.
        $this->actingAs($this->teacher)->postJson(route('exams.manage.omr.store', $exam), ['code' => $code, 'answers' => [$q1->id => [0], $q2->id => [0]]])->assertJson(['score' => 2]);
        $this->assertSame(1, Attempt::where('exam_id', $exam->id)->count());

        $this->actingAs($this->teacher)->postJson(route('exams.manage.omr.store', $exam), ['code' => 'EXOMR:'.$exam->id.':'.$student->id.':AAAAAAAAAA', 'answers' => []])->assertStatus(422);
        $this->actingAs($student)->postJson(route('exams.manage.omr.store', $exam), ['code' => $code, 'answers' => []])->assertForbidden();
    }

    public function test_an_adaptive_exam_climbs_with_right_answers(): void
    {
        $this->teacher = User::factory()->create(['is_examiner' => true]);
        $cat = BankCategory::create(['user_id' => $this->teacher->id, 'name' => 'رياضيات']);
        foreach ([1, 1, 2, 2, 3, 3] as $i => $d) {
            Question::create(['bank_category_id' => $cat->id, 'type' => 'short', 'body' => ['ar' => "س{$i}"], 'points' => 1, 'difficulty' => $d, 'settings' => ['answers' => ['نعم']]]);
        }
        $exam = $this->exam(['adaptive' => ['category_id' => $cat->id, 'count' => 3, 'points' => 2]]);
        $this->assertSame('sequential', $exam->navigation);
        $this->assertEquals(6, $exam->maxScore());
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $order = $attempt->question_order;
        $this->assertCount(1, $order);
        $this->assertSame(2, Question::find($order[0])->difficulty);

        $answer = fn ($text) => $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => end($attempt->refresh()->question_order), 'response' => ['text' => $text]])->assertOk();
        $answer('نعم');
        $this->actingAs($student)->postJson(route('exams.attempts.advance', $attempt), ['to' => 1])->assertOk();
        $this->assertSame(3, Question::find($attempt->fresh()->question_order[1])->difficulty);
        $answer('لا');
        $this->actingAs($student)->postJson(route('exams.attempts.advance', $attempt), ['to' => 2])->assertOk();
        $this->assertSame(2, Question::find($attempt->fresh()->question_order[2])->difficulty);
        // The length is reached: no more questions.
        $this->actingAs($student)->postJson(route('exams.attempts.advance', $attempt), ['to' => 3]);
        $this->assertCount(3, $attempt->fresh()->question_order);
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertInertia(fn (AssertableInertia $p) => $p->has('questions', 3)->where('exam.adaptive.total', 3));
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));
        $this->assertEquals(2, $attempt->fresh()->score);
        $this->assertEquals(6, $attempt->fresh()->max_score);
    }

    public function test_the_standby_server_copies_the_main_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $exam = $this->exam();
        $this->actingAs($admin)->post(route('exams.monitor.mirror.key'))->assertSessionHas('mirror_key_shown');
        $key = Crypt::decryptString(Setting::get('mirror_key'));
        $this->getJson('/mirror/changes')->assertForbidden();
        $payload = $this->withHeaders(['X-Mirror-Key' => $key])->getJson('/mirror/changes')->assertOk()->json();
        $this->assertNotEmpty($payload['tables']['exams']);
        $this->assertNotEmpty($payload['tables']['users']);

        // The standby (here the same database, emptied of the exam) takes it back.
        $exam->delete();
        Http::fake(['main.test/*' => Http::response($payload)]);
        Setting::setMany(['mirror_from' => 'http://main.test', 'mirror_from_key' => Crypt::encryptString($key)]);
        $r = app(Mirror::class)->pull();
        $this->assertGreaterThan(0, $r['rows']);
        $this->assertTrue(Exam::whereKey($exam->id)->exists());
        $this->assertNotEmpty(Setting::get('mirror_last_ok'));
        Http::assertSent(fn ($req) => $req->hasHeader('X-Mirror-Key', $key));
        $this->actingAs($this->teacher)->get(route('exams.monitor.mirror'))->assertForbidden();
    }
}
