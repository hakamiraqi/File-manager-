<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\Exams\Question;
use App\Models\Setting;
use App\Models\User;
use App\Services\Exams\Sis\SisLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** «ربط نظام الطلبة»: connect with SIS's key, take a course section as a group, send marks to a component. */
class SisConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://sis.test/api/connectors/v1/exams';

    private function offering(array $extra = []): array
    {
        return array_replace([
            'id' => 7, 'course' => ['code' => 'C101', 'name_ar' => 'تشريح', 'name_en' => null], 'college' => ['code' => '01', 'name_ar' => 'طب'],
            'department' => 'التشريح', 'term' => ['id' => 1, 'code' => 'Y', 'name_ar' => 'سنوي', 'year' => '2026-2027'],
            'stage' => 1, 'section' => 'A', 'shift' => 'morning', 'teacher' => 'د. علي', 'status' => 'open', 'current_round' => 1, 'is_evaluative' => false, 'students' => 2,
            'components' => [['code' => 'coursework', 'name_ar' => 'السعي', 'max_mark' => 40, 'exam_round' => 1, 'accepts_marks' => true],
                ['code' => 'final', 'name_ar' => 'الامتحان النهائي', 'max_mark' => 60, 'exam_round' => 1, 'accepts_marks' => true]],
        ], $extra);
    }

    private function fakeSis(): void
    {
        Http::fake([
            'sis.test/api/connectors/v1/exams/manifest' => Http::response(['system' => 'sis', 'university' => 'جامعة', 'tools' => [
                ['key' => 'rosters', 'enabled' => true], ['key' => 'marks_inbox', 'enabled' => true]]]),
            'sis.test/api/connectors/v1/exams/offerings' => Http::response(['data' => [$this->offering()]]),
            'sis.test/api/connectors/v1/exams/offerings/7/students' => Http::response(['offering_id' => 7, 'round' => 1, 'data' => [
                ['university_number' => '2026001', 'name_ar' => 'زينب', 'shift' => 'morning', 'attempt' => 1],
                ['university_number' => '2026002', 'name_ar' => 'حسن', 'shift' => 'morning', 'attempt' => 1]]]),
            'sis.test/api/connectors/v1/exams/offerings/7/marks' => Http::response(['batch_id' => 31, 'status' => 'pending', 'accepted' => 2, 'unknown' => []], 202),
            'sis.test/api/connectors/v1/exams/batches/31' => Http::response(['batch_id' => 31, 'status' => 'applied']),
            'wrong.test/*' => Http::response(['message' => 'no'], 401),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_master_admin' => true, 'can_manage_users' => true]);
    }

    public function test_only_an_admin_connects_and_the_key_is_kept_encrypted(): void
    {
        $this->fakeSis();
        $teacher = User::factory()->create(['is_examiner' => true]);
        $this->actingAs($teacher)->post(route('exams.monitor.sis.connect'), ['url' => self::URL, 'key' => str_repeat('k', 30)])->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('exams.monitor.sis.connect'), ['url' => 'https://wrong.test/x', 'key' => str_repeat('k', 30)])->assertSessionHasErrors('url');
        $this->actingAs($admin)->post(route('exams.monitor.sis.connect'), ['url' => self::URL.'/', 'key' => 'sisc_'.str_repeat('k', 30)])->assertSessionHasNoErrors();
        $this->assertTrue(SisLink::connected());
        $this->assertNotSame('sisc_'.str_repeat('k', 30), Setting::get('sis_key'));
        $this->assertSame(['connected' => true, 'rosters' => true, 'marks' => true], SisLink::forPage());
        $this->actingAs($admin)->get(route('exams.monitor.sis'))->assertOk();

        $this->actingAs($admin)->delete(route('exams.monitor.sis.disconnect'));
        $this->assertFalse(SisLink::connected());
    }

    public function test_a_section_becomes_a_group_and_the_marks_go_to_its_component(): void
    {
        $this->fakeSis();
        $this->actingAs($this->admin())->post(route('exams.monitor.sis.connect'), ['url' => self::URL, 'key' => 'sisc_'.str_repeat('k', 30)]);
        $teacher = User::factory()->create(['is_examiner' => true]);
        $known = User::factory()->create(['student_number' => '2026001', 'name' => 'زينب']);

        $this->actingAs($teacher)->getJson(route('exams.manage.sis.offerings'))->assertOk()->assertJsonPath('data.0.group_id', null);
        $this->actingAs($teacher)->post(route('exams.manage.groups.from-sis'), ['offering_id' => 7])->assertSessionHas('success');
        $group = Group::where('sis_offering_id', 7)->firstOrFail();
        $this->assertSame(2, $group->members()->count());
        $this->assertTrue($group->members()->whereKey($known->id)->exists(), 'an existing student is matched by number');
        $this->assertSame(2, User::whereIn('student_number', ['2026001', '2026002'])->count());
        // taking it again updates, it does not duplicate
        $this->actingAs($teacher)->post(route('exams.manage.groups.from-sis'), ['offering_id' => 7]);
        $this->assertSame(1, Group::count());

        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'النهائي'], 'languages' => ['ar']]);
        Question::create(['exam_id' => $exam->id, 'type' => 'single', 'body' => ['ar' => 'س'], 'points' => 20]);
        $exam->groups()->attach($group->id);
        Attempt::create(['exam_id' => $exam->id, 'user_id' => $known->id, 'number' => 1, 'started_at' => now(), 'submitted_at' => now(), 'score' => 15, 'max_score' => 20]);

        $this->actingAs($teacher)->get(route('exams.manage.results', $exam))
            ->assertInertia(fn ($p) => $p->where('sis.marks', true)->where('sis.group_offerings', [7]));
        $this->actingAs($teacher)->post(route('exams.manage.sis.marks', $exam), ['offering_id' => 7, 'component' => 'final', 'absent' => true])
            ->assertSessionHas('success');
        Http::assertSent(function (HttpRequest $r) {
            $m = collect($r['marks'] ?? [])->keyBy('university_number');

            return str_ends_with($r->url(), '/offerings/7/marks') && $r['component'] === 'final'
                && (float) ($m['2026001']['mark'] ?? -1) === 45.0 && ($m['2026002']['absent'] ?? false) === true
                && $r->hasHeader('Authorization', 'Bearer sisc_'.str_repeat('k', 30));
        });
        $this->assertSame('pending', DB::table('exam_sis_pushes')->value('status'));

        $this->actingAs($teacher)->post(route('exams.manage.sis.marks.status', $exam));
        $this->assertSame('applied', DB::table('exam_sis_pushes')->value('status'));

        // another teacher's exam cannot be sent by them
        $other = User::factory()->create(['is_examiner' => true]);
        $this->actingAs($other)->post(route('exams.manage.sis.marks', $exam), ['offering_id' => 7, 'component' => 'final'])->assertForbidden();
    }

    public function test_unmarked_essays_block_sending(): void
    {
        $this->fakeSis();
        $this->actingAs($this->admin())->post(route('exams.monitor.sis.connect'), ['url' => self::URL, 'key' => 'sisc_'.str_repeat('k', 30)]);
        $teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'النهائي'], 'languages' => ['ar']]);
        Question::create(['exam_id' => $exam->id, 'type' => 'essay', 'body' => ['ar' => 'س'], 'points' => 10]);
        Attempt::create(['exam_id' => $exam->id, 'user_id' => User::factory()->create(['student_number' => '1'])->id, 'number' => 1,
            'started_at' => now(), 'submitted_at' => now(), 'needs_grading' => true]);

        $this->actingAs($teacher)->post(route('exams.manage.sis.marks', $exam), ['offering_id' => 7, 'component' => 'final'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'لم تُصحّح'));
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/marks'));
    }
}
