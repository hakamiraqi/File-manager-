<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Computer;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class IntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private function exam(array $settings = []): Exam
    {
        $this->teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $questions = [];
        foreach (range(1, 6) as $i) {
            $questions[] = ['type' => 'single', 'points' => 1, 'body' => ['ar' => "سؤال {$i}"], 'choices' => [
                ['body' => ['ar' => 'أ'], 'is_correct' => true], ['body' => ['ar' => 'ب'], 'is_correct' => false],
                ['body' => ['ar' => 'ج'], 'is_correct' => false], ['body' => ['ar' => 'د'], 'is_correct' => false],
            ]];
        }
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $settings + [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1, 'duration_minutes' => 30,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => $questions,
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_room_stop_gives_every_running_attempt_its_minutes_back(): void
    {
        $exam = $this->exam();
        [$a, $b, $c] = User::factory()->count(3)->create();
        $hall = Computer::create(['room' => 'A', 'label' => 'PC-1', 'ip' => '10.0.0.1']);
        $other = Computer::create(['room' => 'B', 'label' => 'PC-9', 'ip' => '10.0.0.9']);
        foreach ([[$a, $hall], [$b, $other]] as [$u, $pc]) {
            DB::table('exam_attendance')->insert(['exam_id' => $exam->id, 'user_id' => $u->id, 'computer_id' => $pc->id, 'seat' => $pc->label, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([$a, $b, $c] as $u) {
            $this->actingAs($u)->post(route('exams.start', $exam));
        }
        $attempts = Attempt::orderBy('id')->get();
        $before = $attempts->map(fn ($x) => $x->deadline_at->getTimestamp());

        // Only room A.
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.extend-all', $exam), ['minutes' => 7, 'room' => 'A'])->assertJson(['count' => 1]);
        $after = Attempt::orderBy('id')->get()->map(fn ($x) => $x->deadline_at->getTimestamp());
        $this->assertSame($before[0] + 420, $after[0]);
        $this->assertSame($before[1], $after[1]);

        // The whole exam; a submitted attempt is left alone.
        $this->actingAs($c)->post(route('exams.attempts.submit', $attempts[2]));
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.extend-all', $exam), ['minutes' => 5])->assertJson(['count' => 2]);
        $this->assertSame($before[1] + 300, Attempt::find($attempts[1]->id)->deadline_at->getTimestamp());

        // Students cannot.
        $this->actingAs($a)->postJson(route('exams.monitor.extend-all', $exam), ['minutes' => 5])->assertForbidden();
    }

    public function test_a_submitted_attempt_has_a_receipt_the_teacher_sees_too(): void
    {
        $exam = $this->exam();
        $student = User::factory()->create(['student_number' => '2024001']);
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $this->assertNull($attempt->receipt());
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));

        $receipt = $attempt->fresh()->receipt();
        $this->assertMatchesRegularExpression('/^[0-9A-F]{5}-[0-9A-F]{5}$/', $receipt);
        $this->assertSame($receipt, $attempt->fresh()->receipt());
        $this->actingAs($student)->get(route('exams.attempts.result', $attempt))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('attempt.receipt', $receipt)->where('attempt.student_number', '2024001')->where('attempt.questions', 6));
        $row = collect($this->actingAs($this->teacher)->getJson(route('exams.monitor.data', $exam))->json('rows'))->firstWhere('user.id', $student->id);
        $this->assertSame($receipt, $row['attempt']['receipt']);
    }

    public function test_neighbouring_computers_get_different_versions_with_one_order_each(): void
    {
        $exam = $this->exam(['variants' => 2]);
        $this->assertSame(2, $exam->variants);
        $students = User::factory()->count(4)->create();
        foreach ($students as $i => $u) {
            $pc = Computer::create(['room' => 'A', 'label' => 'PC-'.($i + 1), 'ip' => '10.0.0.'.($i + 1)]);
            DB::table('exam_attendance')->insert(['exam_id' => $exam->id, 'user_id' => $u->id, 'computer_id' => $pc->id, 'seat' => $pc->label, 'created_at' => now(), 'updated_at' => now()]);
            $this->actingAs($u)->post(route('exams.start', $exam));
        }
        $attempts = Attempt::orderBy('id')->get();
        $this->assertSame([1, 2, 1, 2], $attempts->pluck('variant')->all());
        $this->assertSame('B', $attempts[1]->variantLetter());

        // Version A keeps the written order; the same version is the same for everyone.
        $written = $exam->questions()->orderBy('position')->pluck('id')->all();
        $this->assertSame($written, $attempts[0]->question_order);
        $this->assertSame($attempts[1]->question_order, $attempts[3]->question_order);
        $this->assertNotSame($written, $attempts[1]->question_order);

        $choicesOf = fn ($u, $a) => $this->actingAs($u)->get(route('exams.attempts.take', $a))->viewData('page')['props']['questions'][0]['choices'];
        $this->assertSame(collect($choicesOf($students[1], $attempts[1]))->pluck('id')->all(), collect($choicesOf($students[3], $attempts[3]))->pluck('id')->all());
        $this->actingAs($students[1])->get(route('exams.attempts.take', $attempts[1]))->assertInertia(fn (AssertableInertia $p) => $p->where('hall.variant', 'B'));
    }

    public function test_the_questions_page_is_protected_unless_switched_off(): void
    {
        $exam = $this->exam();
        $student = User::factory()->create(['name' => 'سارة']);
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('hall.protect', true)->where('hall.watermark', fn ($w) => str_contains($w, 'سارة')));
        $this->actingAs($student)->postJson(route('exams.attempts.event', $attempt), ['type' => 'copy'])->assertOk();
        $this->assertSame(1, $attempt->events()->where('type', 'copy')->count());

        $exam->update(['protect_content' => false]);
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertInertia(fn (AssertableInertia $p) => $p->where('hall.protect', false));
    }

    public function test_students_sign_in_with_their_number(): void
    {
        $student = User::factory()->create(['student_number' => 'S-77']);
        $this->post('/login', ['email' => 'S-77', 'password' => 'wrong']);
        $this->assertGuest();
        $this->post('/login', ['email' => 'S-77', 'password' => 'password']);
        $this->assertAuthenticatedAs($student);
    }

    public function test_admin_adds_a_student_with_a_number_and_no_email(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'علي', 'email' => '', 'student_number' => '2025010', 'password' => 'Secret-Pass-123', 'role' => 'user',
        ])->assertSessionHasNoErrors();
        $ali = User::where('student_number', '2025010')->firstOrFail();
        $this->assertStringEndsWith('@students.invalid', $ali->email);

        // Neither an e-mail nor a number: refused.
        $this->actingAs($admin)->post(route('admin.users.store'), ['name' => 'x', 'email' => '', 'student_number' => '', 'password' => 'Secret-Pass-123', 'role' => 'user'])
            ->assertSessionHasErrors('email');

        // Groups take the number too, and the login cards show it.
        $group = Group::create(['name' => 'المرحلة الأولى', 'user_id' => $admin->id]);
        $this->actingAs($admin)->post(route('exams.groups.members.store', $group), ['emails' => "2025010\nnobody@x.test"])->assertSessionHasNoErrors();
        $this->assertTrue($group->members()->whereKey($ali->id)->exists());
    }
}
