<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invigilator_watches_and_runs_the_hall(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        $invigilator = User::factory()->create(['is_invigilator' => true]);
        [$ali, $sara, $omar] = User::factory()->count(3)->create();
        $group = Group::create(['user_id' => $teacher->id, 'name' => 'أ']);
        $group->members()->attach([$ali->id, $sara->id, $omar->id]);
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'duration_minutes' => 30,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true, 'group_ids' => [$group->id],
            'questions' => [['type' => 'short', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد']]]],
        ])->assertSessionHasNoErrors();

        // Students cannot watch; invigilators see the exam among those running.
        $this->actingAs($ali)->get(route('exams.monitor.index'))->assertForbidden();
        $this->actingAs($invigilator)->get(route('exams.monitor.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('exams', 1));
        $this->actingAs($invigilator)->get(route('exams.manage.edit', $exam))->assertForbidden();

        // Attendance and a seat; Ali starts and answers, Sara starts and goes quiet, Omar is absent.
        $this->actingAs($invigilator)->postJson(route('exams.monitor.attendance', [$exam, $ali]), ['present' => true, 'seat' => 'A3'])->assertOk();
        $this->actingAs($invigilator)->postJson(route('exams.monitor.attendance', [$exam, $omar]), ['present' => false])->assertOk();
        $this->actingAs($ali)->post(route('exams.start', $exam));
        $this->actingAs($sara)->post(route('exams.start', $exam));
        $aliAttempt = Attempt::where('user_id', $ali->id)->firstOrFail();
        $saraAttempt = Attempt::where('user_id', $sara->id)->firstOrFail();
        $this->actingAs($ali)->postJson(route('exams.attempts.save', $aliAttempt), ['question_id' => $exam->questions()->value('id'), 'response' => ['text' => 'بغداد']]);
        $saraAttempt->update(['last_seen_at' => now()->subMinutes(5)]);

        $data = $this->actingAs($invigilator)->getJson(route('exams.monitor.data', $exam))->assertOk()->json();
        $rows = collect($data['rows'])->keyBy('user.id');
        $this->assertSame('working', $rows[$ali->id]['status']);
        $this->assertSame('A3', $rows[$ali->id]['seat']);
        $this->assertSame(1, $rows[$ali->id]['attempt']['answered']);
        $this->assertSame('disconnected', $rows[$sara->id]['status']);
        $this->assertSame('absent', $rows[$omar->id]['status']);
        $this->assertSame(3, $data['counts']['total']);

        // More time reaches the student's page with the next heartbeat; so does an announcement.
        $before = $aliAttempt->fresh()->secondsLeft();
        $this->actingAs($invigilator)->postJson(route('exams.monitor.extend', $aliAttempt), ['minutes' => 10])->assertOk();
        $this->actingAs($invigilator)->postJson(route('exams.monitor.announce', $exam), ['body' => 'بقيت 10 دقائق'])->assertOk();
        $ping = $this->actingAs($ali)->postJson(route('exams.attempts.ping', $aliAttempt))->assertOk()->json();
        $this->assertGreaterThan($before + 500, $ping['seconds_left']);
        $this->assertSame('بقيت 10 دقائق', $ping['announcements'][0]['body']);

        // Submit one, reopen it, then end the session for everyone.
        $this->actingAs($invigilator)->postJson(route('exams.monitor.submit', $aliAttempt))->assertOk();
        $this->assertSame(1.0, $aliAttempt->fresh()->score);
        $this->actingAs($ali)->postJson(route('exams.attempts.ping', $aliAttempt))->assertJson(['closed' => true]);
        $this->actingAs($invigilator)->postJson(route('exams.monitor.reopen', $aliAttempt), ['minutes' => 5])->assertOk();
        $this->assertFalse($aliAttempt->fresh()->isSubmitted());
        $this->actingAs($invigilator)->postJson(route('exams.monitor.submit-all', $exam))->assertOk();
        $this->assertSame(0, Attempt::whereNull('submitted_at')->count());

        // A new access code; the attendance sheet.
        $code = $this->actingAs($invigilator)->postJson(route('exams.monitor.access-code', $exam))->json('access_code');
        $this->assertMatchesRegularExpression('/^[2-9A-Z]{6}$/', $code);
        $this->assertSame($code, $exam->fresh()->access_code);
        $this->actingAs($invigilator)->get(route('exams.monitor.sheet', $exam))->assertInertia(fn (AssertableInertia $p) => $p->has('rows', 3));
    }

    public function test_a_teacher_watches_only_their_own_exams(): void
    {
        $owner = User::factory()->create(['is_examiner' => true]);
        $other = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $owner->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'is_published' => true]);

        $this->actingAs($other)->get(route('exams.monitor.show', $exam))->assertNotFound();
        $this->actingAs($owner)->get(route('exams.monitor.show', $exam))->assertOk();
    }
}
