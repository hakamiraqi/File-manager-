<?php

namespace Tests\Feature;

use App\Models\Exams\Computer;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_finds_what_they_may_see(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true, 'is_invigilator' => true]);
        $other = User::factory()->create(['is_examiner' => true]);
        Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان الأحياء'], 'languages' => ['ar'], 'is_published' => true]);
        Exam::create(['user_id' => $other->id, 'title' => ['ar' => 'امتحان الأحياء الثاني'], 'languages' => ['ar']]);
        $student = User::factory()->create(['name' => 'أحمد علي', 'student_number' => '2025100']);
        Group::create(['user_id' => $teacher->id, 'name' => 'شعبة الأحياء']);
        Computer::create(['label' => 'PC-07', 'room' => 'قاعة 1', 'ip' => '10.0.0.7']);

        $found = collect($this->actingAs($teacher)->getJson(route('search', ['q' => 'الاحياء']))->assertOk()->json('results'));
        $this->assertSame(['exam', 'group'], $found->pluck('kind')->unique()->values()->all());
        $this->assertCount(1, $found->where('kind', 'exam'));
        $this->assertSame('أحمد علي', $this->actingAs($teacher)->getJson(route('search', ['q' => '2025100']))->json('results.0.title'));
        $this->assertSame('computer', $this->actingAs($teacher)->getJson(route('search', ['q' => 'PC-07']))->json('results.0.kind'));

        // A student finds their exams only: no students, groups or computers.
        $mine = collect($this->actingAs($student)->getJson(route('search', ['q' => 'الأحياء']))->json('results'));
        $this->assertSame(['exam'], $mine->pluck('kind')->unique()->values()->all());
        $this->assertCount(1, $mine);
        $this->assertSame([], $this->actingAs($student)->getJson(route('search', ['q' => 'PC-07']))->json('results'));
    }
}
