<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\BankCategory;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Setting;
use App\Models\User;
use App\Services\Exams\CentralSync;
use App\Services\Exams\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OnlineServicesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private function exam(array $settings = []): Exam
    {
        $this->admin ??= User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $exam = Exam::create(['user_id' => $this->admin->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->admin)->put(route('exams.manage.update', $exam), $settings + [
            'title' => ['ar' => 'امتحان مركزي'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'duration_minutes' => 30,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => [['type' => 'short', 'points' => 2, 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد']]]],
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_hall_servers_take_exams_and_send_results(): void
    {
        $exam = $this->exam(['hall_sync' => true]);
        $this->assertNotNull($exam->transfer_key);
        $this->actingAs($this->admin)->post(route('exams.monitor.central.key'))->assertSessionHas('central_key_shown');
        $key = CentralSync::hallKey();

        // The central side.
        $this->getJson('/central/exams')->assertForbidden();
        $list = $this->withHeaders(['X-Hall-Key' => $key])->getJson('/central/exams')->assertOk()->json();
        $this->assertSame($exam->transfer_key, $list['exams'][0]['transfer_key']);
        $package = $this->withHeaders(['X-Hall-Key' => $key])->getJson('/central/exams/'.$exam->transfer_key)->assertOk()->json();

        // A hall server (here: the same database without the exam) takes it…
        $tk = $exam->transfer_key;
        $exam->delete();
        Http::fake([
            'central.test/central/exams' => Http::response($list),
            'central.test/central/exams/*' => Http::response($package),
            'central.test/central/results/*' => Http::response(['attempts' => 1, 'accounts' => 0]),
        ]);
        Setting::setMany(['central_url' => 'http://central.test', 'central_key' => Crypt::encryptString($key)]);
        $r = app(CentralSync::class)->run();
        $this->assertSame(1, $r['taken']);
        $copy = Exam::where('transfer_key', $tk)->firstOrFail();
        $this->assertFalse($copy->is_published);

        // …and once a student has sat it, sends the results.
        $copy->update(['is_published' => true]);
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $copy));
        $this->actingAs($student)->post(route('exams.attempts.submit', Attempt::firstOrFail()));
        $r = app(CentralSync::class)->run();
        $this->assertSame(0, $r['taken']);
        $this->assertSame(1, $r['sent']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/central/results/'.$tk) && $req['format'] === 'examnest-results');

        // The central side takes a results bundle into its exam.
        $bundle = app(\App\Services\Exams\Transfer::class)->exportResults($copy);
        $this->withHeaders(['X-Hall-Key' => $key])->postJson('/central/results/'.$tk, $bundle)->assertOk();
        $this->withHeaders(['X-Hall-Key' => 'wrong'])->postJson('/central/results/'.$tk, $bundle)->assertForbidden();
    }

    public function test_marks_go_out_by_sms(): void
    {
        $exam = $this->exam();
        $student = User::factory()->create(['phone' => '0770 123 4567', 'name' => 'زينب']);
        $silent = User::factory()->create();
        foreach ([$student, $silent] as $u) {
            $this->actingAs($u)->post(route('exams.start', $exam));
            $attempt = Attempt::where('user_id', $u->id)->first();
            $this->actingAs($u)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $exam->questions()->value('id'), 'response' => ['text' => 'بغداد']]);
            $this->actingAs($u)->post(route('exams.attempts.submit', $attempt));
        }
        $this->assertSame('9647701234567', Messenger::normalize('0770 123 4567'));

        $this->actingAs($this->admin)->postJson(route('exams.manage.notify', $exam), ['kind' => 'results'])->assertNotFound();
        $this->actingAs($this->admin)->post(route('exams.monitor.central.messages'), ['url' => 'https://sms.test/send?to={phone}', 'method' => 'POST',
            'body' => '{"to": "{phone}", "text": "{message}"}', 'header' => 'Authorization: Bearer abc', 'country' => '964'])->assertSessionHasNoErrors();
        Http::fake(['sms.test/*' => Http::response(['ok' => true])]);
        $this->actingAs($this->admin)->postJson(route('exams.manage.notify', $exam), ['kind' => 'results'])->assertJson(['sent' => 1, 'failed' => 0, 'no_phone' => 1]);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'to=9647701234567') && $req->hasHeader('Authorization', 'Bearer abc')
            && str_contains($req->body(), 'زينب') && str_contains($req->body(), '2'));
    }

    public function test_ai_drafts_questions_from_a_lecture(): void
    {
        $this->admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true, 'is_examiner' => true]);
        $cat = BankCategory::create(['user_id' => $this->admin->id, 'name' => 'كيمياء']);
        $this->actingAs($this->admin)->postJson(route('exams.manage.bank.categories.generate', $cat), ['text' => 'الماء H2O', 'count' => 2, 'types' => ['single']])->assertNotFound();

        Setting::set('ai_api_key', Crypt::encryptString('sk-test'));
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['questions' => [
            ['type' => 'single', 'body' => 'ما صيغة الماء؟', 'choices' => [['text' => 'H2O', 'correct' => true], ['text' => 'CO2', 'correct' => false]], 'difficulty' => 1, 'tag' => 'المركبات'],
            ['type' => 'single', 'body' => 'سؤال بلا جواب صحيح', 'choices' => [['text' => 'أ', 'correct' => false], ['text' => 'ب', 'correct' => false]]],
            ['type' => 'true_false', 'body' => 'الماء مركب', 'answer' => true],
        ]], JSON_UNESCAPED_UNICODE)]]])]);
        $drafts = $this->actingAs($this->admin)->postJson(route('exams.manage.bank.categories.generate', $cat), ['text' => 'الماء H2O مركب', 'count' => 5, 'types' => ['single', 'true_false']])
            ->assertOk()->json('questions');
        $this->assertCount(2, $drafts);   // the one without a right answer is dropped
        Http::assertSent(fn ($req) => str_contains(json_encode($req['messages'], JSON_UNESCAPED_UNICODE), 'الماء H2O مركب'));

        $this->actingAs($this->admin)->post(route('exams.manage.bank.categories.generated', $cat), ['lang' => 'ar', 'questions' => $drafts])->assertSessionHasNoErrors();
        $this->assertSame(2, Question::where('bank_category_id', $cat->id)->count());
        $tf = Question::where('type', 'true_false')->with('choices')->first();
        $this->assertTrue($tf->choices->firstWhere('is_correct', true)->body['ar'] === 'صح');
        $this->assertSame(['المركبات'], Question::where('type', 'single')->value('tags') ? Question::where('type', 'single')->first()->tags : null);
    }

    public function test_the_university_dashboard(): void
    {
        $exam = $this->exam(['department' => 'الكيمياء', 'subject' => 'عضوية', 'pass_mark' => 50]);
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $exam->questions()->value('id'), 'response' => ['text' => 'بغداد']]);
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));

        $this->actingAs($this->admin)->get(route('exams.manage.dashboard'))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('totals.exams', 1)->where('totals.students', 1)->where('departments.0.name', 'الكيمياء')
            ->where('departments.0.pass_rate', fn ($v) => $v == 100)->where('departments.0.subjects.0.name', 'عضوية'));
        $this->actingAs(User::factory()->create(['is_examiner' => true]))->get(route('exams.manage.dashboard'))->assertForbidden();
    }
}
