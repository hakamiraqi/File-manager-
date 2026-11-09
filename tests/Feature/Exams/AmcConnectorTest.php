<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\User;
use App\Services\Exams\AmcConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AmcConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_server_runs_amc_for_the_teacher(): void
    {
        config(['services.amc.url' => 'http://amc.test:8080', 'services.amc.token' => 'tok']);
        $teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'كيمياء'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'grading_method' => 'highest', 'show_results' => 'after_submit',
            'questions' => [['type' => 'single', 'points' => 2, 'body' => ['ar' => 'رمز الماء؟'], 'choices' => [['body' => ['ar' => 'H2O'], 'is_correct' => true], ['body' => ['ar' => 'CO2'], 'is_correct' => false]]]],
        ])->assertSessionHasNoErrors();
        $student = User::factory()->create();
        DB::table('exam_attendance')->insert(['exam_id' => $exam->id, 'user_id' => $student->id, 'created_at' => now(), 'updated_at' => now()]);
        $q = $exam->questions()->value('id');
        $p = AmcConnector::project($exam);

        Http::fake([
            'amc.test:8080/health' => Http::response(['ok' => true, 'amc' => '1.5.2']),
            "amc.test:8080/projects/$p" => Http::response(['state' => 'built', 'pages' => 2]),
            "amc.test:8080/projects/$p/status" => Http::sequence()->push(['state' => 'built', 'pages' => 2, 'scans' => 0])->push(['state' => 'ready', 'scans' => 1]),
            "amc.test:8080/projects/$p/subject.pdf" => Http::response('%PDF-1.4 sheets', 200, ['Content-Type' => 'application/pdf']),
            "amc.test:8080/projects/$p/scans*" => Http::response(['ok' => true]),
            "amc.test:8080/projects/$p/process" => Http::response(['ok' => true], 202),
            "amc.test:8080/projects/$p/results.csv" => Http::response("copy,key,id,name,Mark,TICKED:q$q\n1,S{$student->id},S{$student->id},أ,20,A\n"),
        ]);

        $this->actingAs($teacher)->getJson(route('exams.manage.amc.server', $exam))->assertJson(['configured' => true, 'health' => ['ok' => true], 'project' => ['state' => 'built']]);
        $this->actingAs($teacher)->postJson(route('exams.manage.amc.server.build', $exam))->assertJson(['state' => 'built']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->hasHeader('Authorization', 'Bearer tok') && str_contains($r['source'], "id=q$q") && str_contains($r['students'], 'S'.$student->id));
        $this->actingAs($teacher)->get(route('exams.manage.amc.server.pdf', $exam))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($teacher)->post(route('exams.manage.amc.server.scans', $exam), ['file' => UploadedFile::fake()->createWithContent('مسح 1.pdf', '%PDF-1.4 scan')])->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/scans?name=') && $r->body() === '%PDF-1.4 scan');
        $this->actingAs($teacher)->postJson(route('exams.manage.amc.server.process', $exam))->assertOk();
        $this->actingAs($teacher)->postJson(route('exams.manage.amc.server.import', $exam))->assertJson(['attempts' => 1, 'how' => 'ticked']);
        $this->assertEquals(2, Attempt::where('user_id', $student->id)->value('score'));

        $this->actingAs(User::factory()->create())->postJson(route('exams.manage.amc.server.build', $exam))->assertForbidden();
    }

    public function test_without_a_connector_nothing_shows(): void
    {
        config(['services.amc.url' => null, 'services.amc.token' => null]);
        $teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($teacher)->getJson(route('exams.manage.amc.server', $exam))->assertJson(['configured' => false]);
        $this->actingAs($teacher)->postJson(route('exams.manage.amc.server.build', $exam))->assertNotFound();
    }
}
