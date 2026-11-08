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

class HallControlTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private function exam(array $settings = []): Exam
    {
        $this->teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $settings + [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'duration_minutes' => 30,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => [['type' => 'short', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد']]]],
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_leaving_or_reopening_locks_the_attempt_until_the_code(): void
    {
        $exam = $this->exam(['resume_code_required' => true]);
        $student = User::factory()->create();
        $qid = $exam->questions()->value('id');
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertInertia(fn (AssertableInertia $p) => $p->where('hall.locked', false));

        // Leaving the page locks it; saving is refused; the invigilator sees the code.
        $this->actingAs($student)->postJson(route('exams.attempts.event', $attempt), ['type' => 'hidden']);
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $qid, 'response' => ['text' => 'بغداد']])->assertStatus(423);
        $this->actingAs($student)->postJson(route('exams.attempts.ping', $attempt))->assertJson(['locked' => true]);
        $code = $attempt->fresh()->resume_code;
        $row = collect($this->actingAs($this->teacher)->getJson(route('exams.monitor.data', $exam))->json('rows'))->firstWhere('user.id', $student->id);
        $this->assertSame(['reason' => 'left', 'code' => $code], $row['attempt']['locked']);

        $this->actingAs($student)->postJson(route('exams.attempts.unlock', $attempt), ['code' => '0000'])->assertStatus(422);
        $this->actingAs($student)->postJson(route('exams.attempts.unlock', $attempt), ['code' => $code])->assertOk();
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $qid, 'response' => ['text' => 'بغداد']])->assertOk();
        // The same page keeps working after a reload in its own session.
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertInertia(fn (AssertableInertia $p) => $p->where('hall.locked', false));

        // Opened in another session (another browser, another computer): locked.
        $this->flushSession();
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertInertia(fn (AssertableInertia $p) => $p->where('hall.locked', true));
        $this->assertSame('reopened', $attempt->fresh()->lock_reason);

        // The invigilator opens it, or switches the code off for everyone.
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.unlock', $attempt))->assertOk();
        $this->assertNull($attempt->fresh()->locked_at);
        $attempt->fresh()->lock('left');
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.settings', $exam), ['resume_code_required' => false])->assertOk();
        $this->assertNull($attempt->fresh()->locked_at);
        $this->actingAs($student)->postJson(route('exams.attempts.event', $attempt), ['type' => 'hidden']);
        $this->assertNull($attempt->fresh()->locked_at);
    }

    public function test_help_requests_reach_the_invigilator(): void
    {
        $exam = $this->exam();
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();

        $this->actingAs($student)->postJson(route('exams.attempts.help', $attempt), ['on' => true])->assertJson(['help' => true]);
        $data = $this->actingAs($this->teacher)->getJson(route('exams.monitor.data', $exam))->json();
        $this->assertSame(1, $data['counts']['help']);
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.help-done', $attempt))->assertOk();
        $this->actingAs($student)->postJson(route('exams.attempts.ping', $attempt))->assertJson(['help' => false]);
    }

    public function test_computers_seating_and_binding(): void
    {
        $exam = $this->exam(['bind_computers' => true]);
        $group = Group::create(['user_id' => $this->teacher->id, 'name' => 'أ']);
        [$ali, $sara, $omar] = User::factory()->count(3)->create();
        $group->members()->attach([$ali->id, $sara->id, $omar->id]);
        $exam->groups()->sync([$group->id]);

        // Registering this computer, and several at once.
        $this->actingAs($this->teacher)->withServerVariables(['REMOTE_ADDR' => '192.168.1.11'])->get(route('exams.monitor.computers'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('here.ip', '192.168.1.11')->where('here.computer', null));
        $this->actingAs($this->teacher)->post(route('exams.monitor.computers.store'), ['label' => 'PC-01', 'room' => 'قاعة 1', 'ip' => '192.168.1.11'])->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post(route('exams.monitor.computers.store'), ['bulk' => "PC-02 192.168.1.12\nPC-10 192.168.1.20\nrubbish", 'room' => 'قاعة 1'])->assertSessionHasNoErrors();
        $this->assertSame(3, Computer::count());
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.12'])->get('/ip')->assertSee('PC-02')->assertSee('192.168.1.12');

        // Omar is absent; the other two are seated by name on PC-01, PC-02 (natural order, not PC-10).
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.attendance', [$exam, $omar]), ['present' => false]);
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.distribute', $exam), ['room' => 'قاعة 1', 'order' => 'name'])
            ->assertJson(['seated' => 2, 'students' => 2, 'computers' => 3]);
        $first = collect([$ali, $sara])->sortBy('name')->first();
        $this->assertSame('PC-01', $exam->computerFor($first)->label);

        // Bound: only from their own computer.
        $pc = $exam->computerFor($ali);
        $this->actingAs($ali)->withServerVariables(['REMOTE_ADDR' => '192.168.1.99'])->from(route('exams.show', $exam))
            ->post(route('exams.start', $exam))->assertSessionHasErrors(['exam' => "هذه ليست حاسبتك. حاسبتك في هذا الامتحان: {$pc->label} (قاعة 1)."]);
        $this->actingAs($ali)->withServerVariables(['REMOTE_ADDR' => $pc->ip])->post(route('exams.start', $exam))->assertRedirect();
        $attempt = Attempt::where('user_id', $ali->id)->firstOrFail();
        $this->actingAs($ali)->withServerVariables(['REMOTE_ADDR' => $pc->ip])->get(route('exams.attempts.take', $attempt))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('hall.computer', $pc->label));

        // Moving Ali to PC-10 frees his old computer.
        $pc10 = Computer::where('label', 'PC-10')->first();
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.seat', [$exam, $ali]), ['computer_id' => $pc10->id])->assertOk();
        $this->assertSame('PC-10', $exam->computerFor($ali)->label);
    }
}
