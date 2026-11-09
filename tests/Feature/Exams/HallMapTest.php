<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Computer;
use App\Models\Exams\Exam;
use App\Models\Exams\MapItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HallMapTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private function exam(): Exam
    {
        $this->teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان الخريطة'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'duration_minutes' => 30,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => [['type' => 'short', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد']]]],
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    private function layout(array $pcs): void
    {
        $shelves = [['code' => 'قاعة 1', 'kind' => 'hall', 'x' => 10, 'y' => 7, 'rotation' => 0, 'width' => 18, 'depth' => 12, 'levels' => 1,
            'points' => [[-9, -6], [9, -6], [9, 6], [-9, 6]]]];
        foreach ($pcs as $i => $pc) {
            $shelves[] = ['code' => $pc->label, 'kind' => 'pc', 'computer_id' => $pc->id, 'x' => 4 + $i * 2, 'y' => 5, 'rotation' => 0, 'width' => 1.2, 'depth' => 0.7, 'levels' => 1];
        }
        $shelves[] = ['code' => 'QR1', 'kind' => 'qr', 'x' => 1, 'y' => 1.2, 'rotation' => 0, 'width' => 0.3, 'depth' => 0.1, 'levels' => 1];
        $this->actingAs($this->teacher)->put(route('exams.monitor.halls.update'), [
            'floor' => ['width' => 20, 'depth' => 14, 'entrance' => ['x' => 10, 'y' => 13], 'storeys' => ['الأرضي'], 'separate_halls' => false, 'backgrounds' => []],
            'shelves' => $shelves,
        ])->assertSessionHasNoErrors();
    }

    public function test_the_map_shows_each_computer_live(): void
    {
        $exam = $this->exam();
        [$pc1, $pc2, $pc3, $pc4] = collect(range(1, 4))->map(fn ($n) => Computer::create(['label' => "PC-0{$n}", 'room' => 'قاعة 1', 'ip' => "192.168.1.1{$n}"]))->all();
        $this->layout([$pc1, $pc2, $pc3, $pc4]);
        $this->assertSame(6, MapItem::count());
        $this->assertSame($pc2->id, MapItem::where('code', 'PC-02')->value('computer_id'));

        // Computers say they are on from any page; PC-03's clock is 10 minutes slow.
        $this->withServerVariables(['REMOTE_ADDR' => $pc3->ip])->getJson('/hall/ping?t='.(now()->getTimestampMs() - 600000))->assertJson(['computer' => 'PC-03']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->getJson('/hall/ping')->assertJson(['computer' => null]);
        $this->assertEqualsWithDelta(-600, $pc3->fresh()->last_clock_offset, 2);

        // Ali works on PC-01 and asks for help; Sara was seated at PC-04 but sits at PC-02.
        $ali = User::factory()->create(['name' => 'علي']);
        $sara = User::factory()->create(['name' => 'سارة']);
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.seat', [$exam, $sara]), ['computer_id' => $pc4->id])->assertOk();
        $this->actingAs($ali)->withServerVariables(['REMOTE_ADDR' => $pc1->ip])->post(route('exams.start', $exam));
        $this->actingAs($sara)->withServerVariables(['REMOTE_ADDR' => $pc2->ip])->post(route('exams.start', $exam));
        $aliAttempt = Attempt::where('user_id', $ali->id)->firstOrFail();
        $this->assertSame($pc1->ip, $aliAttempt->ip);
        $this->actingAs($ali)->postJson(route('exams.attempts.help', $aliAttempt))->assertOk();

        $live = $this->actingAs($this->teacher)->getJson(route('exams.monitor.halls.live'))->assertOk()->json('computers');
        $this->assertSame('help', $live[$pc1->id]['state']);
        $this->assertSame('علي', $live[$pc1->id]['student']['name']);
        $this->assertSame('wrong_pc', $live[$pc2->id]['state']);
        $this->assertSame('on', $live[$pc3->id]['state']);
        $this->assertContains('clock', $live[$pc3->id]['problems']);
        $this->assertSame('off_expected', $live[$pc4->id]['state']);
        $this->assertSame('سارة', $live[$pc4->id]['expected']['name']);

        // Help given: back to working.
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.help-done', $aliAttempt))->assertOk();
        $this->assertSame('working', $this->actingAs($this->teacher)->getJson(route('exams.monitor.halls.live', ['exam' => $exam->id]))->json("computers.{$pc1->id}.state"));

        $this->actingAs($this->teacher)->get(route('exams.monitor.halls', ['pc' => $pc1->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Exams/Monitor/HallMap')->has('shelves', 6)->where('target', $pc1->id)
                ->where('exams.0.title', 'امتحان الخريطة')->where("live.computers.{$pc2->id}.state", 'wrong_pc'));
        $this->actingAs($this->teacher)->get(route('exams.monitor.halls.labels'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Exams/Monitor/HallMapLabels')->has('items', 5));
        $this->actingAs($this->teacher)->get(route('exams.monitor.halls.edit'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Exams/Monitor/HallMapEdit')->has('computers', 4));
    }

    public function test_one_computer_in_one_place_and_only_managers_draw(): void
    {
        $this->exam();
        $pc = Computer::create(['label' => 'PC-01', 'ip' => '192.168.1.11']);
        $this->actingAs($this->teacher)->put(route('exams.monitor.halls.update'), [
            'floor' => ['width' => 20, 'depth' => 14, 'entrance' => ['x' => 10, 'y' => 13]],
            'shelves' => [
                ['code' => 'A', 'kind' => 'pc', 'computer_id' => $pc->id, 'x' => 2, 'y' => 2, 'rotation' => 0, 'width' => 1.2, 'depth' => 0.7],
                ['code' => 'B', 'kind' => 'pc', 'computer_id' => $pc->id, 'x' => 4, 'y' => 2, 'rotation' => 0, 'width' => 1.2, 'depth' => 0.7],
            ],
        ])->assertSessionHasErrors('shelves.1.computer_id');

        $invigilator = User::factory()->create(['is_invigilator' => true]);
        $this->actingAs($invigilator)->get(route('exams.monitor.halls'))->assertOk();
        $this->actingAs($invigilator)->get(route('exams.monitor.halls.edit'))->assertForbidden();
        $this->actingAs($invigilator)->put(route('exams.monitor.halls.update'), ['floor' => [], 'shelves' => []])->assertForbidden();
    }
}
