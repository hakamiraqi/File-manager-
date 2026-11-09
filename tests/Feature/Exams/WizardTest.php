<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_exam_in_three_steps(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        $group = Group::create(['user_id' => $teacher->id, 'name' => 'أ']);
        $this->actingAs($teacher)->get(route('exams.manage.index'))->assertInertia(fn (AssertableInertia $p) => $p->where('wizard.groups.0.name', 'أ'));

        $this->actingAs($teacher)->post(route('exams.manage.store'), [
            'title' => 'الفصلي الأول', 'subject' => 'التشريح', 'stage' => 'الثانية', 'start' => 'import',
            'group_ids' => [$group->id], 'duration_minutes' => 45, 'opens_at' => now()->addDay()->format('Y-m-d').' 09:00', 'hall' => 'قاعة 1',
        ])->assertRedirect(route('exams.manage.edit', ['exam' => Exam::first()->id, 'start' => 'import']));
        $exam = Exam::firstOrFail();
        $this->assertSame('الفصلي الأول', $exam->title['ar']);
        $this->assertSame(['التشريح', 'الثانية', 45, 'قاعة 1'], [$exam->subject, $exam->stage, $exam->duration_minutes, $exam->hall]);
        $this->assertSame('09:00', $exam->opens_at->format('H:i'));
        $this->assertSame([$group->id], $exam->groups()->pluck('exam_groups.id')->all());
        $this->assertFalse($exam->is_published);

        // A blank one, as before.
        $this->actingAs($teacher)->post(route('exams.manage.store'))->assertRedirect();
        $this->assertSame(2, Exam::count());
    }
}
