<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Computer;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TodayTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_day_on_one_page_with_its_checklist(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        [$ali, $sara] = User::factory()->count(2)->create()->all();
        $group = Group::create(['user_id' => $teacher->id, 'name' => 'أ']);
        $group->members()->attach([$ali->id, $sara->id]);
        Computer::create(['label' => 'PC-01', 'room' => 'قاعة 1', 'ip' => '10.0.0.1']);

        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان اليوم'], 'languages' => ['ar']]);
        $this->actingAs($teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان اليوم'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'duration_minutes' => 30,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true, 'hall' => 'قاعة 1',
            'opens_at' => now()->subMinutes(5)->format('Y-m-d H:i'), 'group_ids' => [$group->id],
            'questions' => [['type' => 'short', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد']]]],
        ])->assertSessionHasNoErrors();
        // Another exam, next week: not today.
        Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'لاحقاً'], 'languages' => ['ar'], 'is_published' => true, 'opens_at' => now()->addWeek()]);

        $this->actingAs($ali)->post(route('exams.start', $exam));
        $this->assertSame(1, Attempt::count());

        $day = $this->actingAs($teacher)->getJson(route('exams.monitor.today'))->assertOk()->json();
        $this->assertCount(1, $day['exams']);
        $e = $day['exams'][0];
        $this->assertSame('running', $e['state']);
        $this->assertSame(2, $e['expected']);
        $this->assertSame(1, $e['running']);
        $check = collect($e['check'])->keyBy('key');
        $this->assertTrue($check['questions']['ok']);
        $this->assertTrue($check['groups']['ok']);
        $this->assertTrue($check['hall']['ok']);
        $this->assertFalse($check['computers_on']['ok']);
        $this->assertFalse($e['ready']);
        $this->assertSame('قاعة 1', $day['halls'][0]['room']);

        $this->actingAs($teacher)->get(route('exams.monitor.today'))->assertInertia(fn (AssertableInertia $p) => $p->component('Exams/Monitor/Today')->has('exams', 1));
        $this->actingAs($ali)->get(route('exams.monitor.today'))->assertForbidden();
    }
}
