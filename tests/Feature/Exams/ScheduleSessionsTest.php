<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\BankCategory;
use App\Models\Exams\Computer;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\Exams\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ScheduleSessionsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private function payload(array $settings = []): array
    {
        return $settings + [
            'title' => ['ar' => $settings['title_ar'] ?? 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'duration_minutes' => 30,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => [['type' => 'short', 'points' => 2, 'tags' => ['الفصل 1'], 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد']]]],
        ];
    }

    private function exam(array $settings = []): Exam
    {
        $this->teacher ??= User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $this->payload($settings))->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_the_timetable_finds_clashes_of_hall_and_group(): void
    {
        $this->teacher = User::factory()->create(['is_examiner' => true]);
        $group = Group::create(['user_id' => $this->teacher->id, 'name' => 'المرحلة الأولى']);
        $at = fn ($h) => now()->addDay()->setTime($h, 0)->format('Y-m-d\TH:i');
        $a = $this->exam(['title_ar' => 'كيمياء', 'hall' => 'القاعة 3', 'opens_at' => $at(9), 'closes_at' => $at(11), 'group_ids' => [$group->id]]);
        $b = $this->exam(['title_ar' => 'فيزياء', 'hall' => 'القاعة 3', 'opens_at' => $at(10), 'closes_at' => $at(12)]);
        $c = $this->exam(['title_ar' => 'أحياء', 'hall' => 'القاعة 5', 'opens_at' => $at(10), 'closes_at' => $at(12), 'group_ids' => [$group->id]]);
        $d = $this->exam(['title_ar' => 'رياضيات', 'hall' => 'القاعة 3', 'opens_at' => $at(13), 'closes_at' => $at(14)]);

        $entries = collect($this->actingAs($this->teacher)->get(route('exams.manage.schedule'))->viewData('page')['props']['entries'])->keyBy('exam_id');
        $this->assertCount(4, $entries);
        $whyA = collect($entries[$a->id]['clashes'])->pluck('why', 'with');
        $this->assertStringContainsString('القاعة', $whyA['فيزياء']);
        $this->assertStringContainsString('المجموعة', $whyA['أحياء']);
        $this->assertSame([], $entries[$d->id]['clashes']);
    }

    public function test_sittings_open_for_each_student_in_their_own_time(): void
    {
        $now = now();
        $exam = $this->exam(['sessions' => [
            ['label' => 'الصباحية', 'opens_at' => $now->copy()->subHour()->format('Y-m-d\TH:i'), 'closes_at' => $now->copy()->addHour()->format('Y-m-d\TH:i')],
            ['label' => 'الظهرية', 'opens_at' => $now->copy()->addHours(2)->format('Y-m-d\TH:i'), 'closes_at' => $now->copy()->addHours(4)->format('Y-m-d\TH:i')],
        ]]);
        $this->assertCount(2, $exam->sessions);
        [$early, $late, $none] = User::factory()->count(3)->create();
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.session', [$exam, $early]), ['session' => 1])->assertOk();
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.session', [$exam, $late]), ['session' => 2])->assertOk();
        DB::table('exam_attendance')->insert(['exam_id' => $exam->id, 'user_id' => $none->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($early)->post(route('exams.start', $exam));
        $this->assertSame(1, Attempt::where('user_id', $early->id)->count());
        $this->assertTrue(Attempt::where('user_id', $early->id)->first()->deadline_at->lte($now->copy()->addHour()->addMinute()));
        $this->actingAs($late)->post(route('exams.start', $exam))->assertSessionHasErrors('exam');
        $this->actingAs($none)->post(route('exams.start', $exam))->assertSessionHasErrors('exam');
        $this->actingAs($late)->get(route('exams.index'))->assertOk();

        // Split evenly: the student who started keeps their sitting.
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.distribute-sessions', $exam), ['order' => 'name'])->assertJson(['students' => 2]);
        $this->assertNotNull(DB::table('exam_attendance')->where('exam_id', $exam->id)->where('user_id', $none->id)->value('session'));
        $this->assertSame(1, (int) DB::table('exam_attendance')->where('exam_id', $exam->id)->where('user_id', $early->id)->value('session'));

        // A sitting must end after it starts.
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $this->payload(['sessions' => [
            ['label' => 'x', 'opens_at' => $now->copy()->addHour()->format('Y-m-d\TH:i'), 'closes_at' => $now->format('Y-m-d\TH:i')]]]))->assertSessionHasErrors('sessions');
    }

    public function test_classmates_are_seated_apart(): void
    {
        $exam = $this->exam();
        $groups = collect(['أ', 'ب'])->map(fn ($n) => Group::create(['user_id' => $this->teacher->id, 'name' => $n]));
        foreach ($groups as $g) {
            $g->members()->attach(User::factory()->count(3)->create()->pluck('id'));
        }
        $exam->groups()->sync($groups->pluck('id'));
        foreach (range(1, 6) as $i) {
            Computer::create(['room' => 'A', 'label' => 'PC-'.$i, 'ip' => '10.0.0.'.$i]);
        }
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.distribute', $exam), ['room' => 'A', 'order' => 'mixed'])->assertJson(['seated' => 6]);
        $groupOf = DB::table('exam_group_user')->pluck('exam_group_id', 'user_id');
        $bySeat = DB::table('exam_attendance')->where('exam_id', $exam->id)->get()->sortBy('seat', SORT_NATURAL)->map(fn ($r) => $groupOf[$r->user_id])->values()->all();
        foreach (range(0, 4) as $i) {
            $this->assertNotSame($bySeat[$i], $bySeat[$i + 1]);
        }
    }

    public function test_open_book_files_only_while_the_attempt_is_open(): void
    {
        Storage::fake('local');
        $exam = $this->exam();
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
        $r = $this->actingAs($this->teacher)->post(route('exams.manage.resources.store', $exam), ['file' => UploadedFile::fake()->createWithContent('جدول دوري.pdf', $pdf)])->assertOk()->json();
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $this->payload(['resources' => [$r]]))->assertSessionHasNoErrors();

        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $url = $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->viewData('page')['props']['hall']['resources'][0]['url'];
        $this->actingAs($student)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($student)->get(route('exams.attempts.resource', [$attempt, str_repeat('a', 40).'.pdf']))->assertNotFound();
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));
        $this->actingAs($student)->get($url)->assertForbidden();
    }

    public function test_the_student_report_and_the_blueprint(): void
    {
        $exam = $this->exam();
        $student = User::factory()->create(['student_number' => '77']);
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $exam->questions()->value('id'), 'response' => ['text' => 'بغداد']]);
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));

        $this->actingAs($this->teacher)->get(route('exams.manage.students.report', $student))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('exams.0.percent', fn ($v) => $v == 100)->where('by_tag.0.tag', 'الفصل 1')->where('average', fn ($v) => $v == 100));
        $this->actingAs(User::factory()->create(['is_examiner' => true]))->get(route('exams.manage.students.report', $student))->assertNotFound();

        $cat = BankCategory::create(['user_id' => $this->teacher->id, 'name' => 'كيمياء']);
        foreach ([[['ف1'], 1], [['ف1'], 2], [['ف2'], 1]] as [$tags, $d]) {
            Question::create(['bank_category_id' => $cat->id, 'type' => 'short', 'body' => ['ar' => 'س'], 'points' => 1, 'tags' => $tags, 'difficulty' => $d]);
        }
        $m = $this->actingAs($this->teacher)->getJson(route('exams.manage.bank.categories.matrix', $cat))->assertOk()->json();
        $this->assertSame(3, $m['total']['0']);
        $this->assertSame(['ف1', 'ف2'], array_column($m['tags'], 'tag'));
        $this->assertSame(1, $m['tags'][0]['counts']['2']);

        $kiosk = $this->actingAs($this->teacher)->get(route('exams.monitor.computers.kiosk'))->assertOk();
        $this->assertStringContainsString('--kiosk', $kiosk->getContent());
    }
}
