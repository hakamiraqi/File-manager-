<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\AnswerLog;
use App\Models\Exams\Attempt;
use App\Models\Exams\Computer;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HallMapCardsTest extends TestCase
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
            'questions' => [
                ['type' => 'single', 'points' => 1, 'body' => ['ar' => 'رمز الماء؟'], 'choices' => [
                    ['body' => ['ar' => 'H2O'], 'is_correct' => true], ['body' => ['ar' => 'CO2'], 'is_correct' => false]]],
                ['type' => 'short', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد']]],
            ],
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_a_room_is_laid_out_in_rows_and_versions_follow_the_seats(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        foreach (range(1, 7) as $i) {
            Computer::create(['room' => 'A', 'label' => 'PC-'.$i, 'ip' => '10.0.0.'.$i]);
        }
        $this->actingAs($admin)->post(route('exams.monitor.computers.arrange'), ['room' => 'A', 'columns' => 3])->assertSessionHasNoErrors();
        $pc = fn ($l) => Computer::where('label', $l)->first();
        $this->assertSame([1, 1], [$pc('PC-1')->seat_row, $pc('PC-1')->seat_col]);
        $this->assertSame([2, 1], [$pc('PC-4')->seat_row, $pc('PC-4')->seat_col]);
        $this->assertSame([3, 1], [$pc('PC-7')->seat_row, $pc('PC-7')->seat_col]);

        // One seat moved by hand.
        $this->actingAs($admin)->put(route('exams.monitor.computers.update', $pc('PC-7')), ['label' => 'PC-7', 'room' => 'A', 'ip' => '10.0.0.7', 'seat_row' => 3, 'seat_col' => 3])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $pc('PC-7')->seat_col);

        // Two versions on the map: the seat beside and the seat behind differ.
        $exam = $this->exam(['variants' => 2]);
        $variantAt = function (string $label) use ($exam) {
            $u = User::factory()->create();
            DB::table('exam_attendance')->insert(['exam_id' => $exam->id, 'user_id' => $u->id, 'computer_id' => Computer::where('label', $label)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
            $this->actingAs($u)->post(route('exams.start', $exam));

            return Attempt::where('user_id', $u->id)->value('variant');
        };
        $first = $variantAt('PC-1');
        $this->assertNotSame($first, $variantAt('PC-2'));
        $this->assertNotSame($first, $variantAt('PC-4'));
        $this->assertSame($first, $variantAt('PC-5'));
    }

    public function test_login_cards_sign_in_and_mark_attendance(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $student = User::factory()->create(['name' => 'زينب']);
        $group = Group::create(['user_id' => $admin->id, 'name' => 'أ']);
        $group->members()->attach($student->id);

        $cards = $this->actingAs($admin)->post(route('exams.manage.groups.cards', $group))->viewData('page')['props']['cards'];
        $url = $cards[0]['card_url'];
        $code = $student->fresh()->card_code;
        $this->assertStringEndsWith('/card/'.$code, $url);
        $this->assertArrayNotHasKey('card_code', $student->fresh()->toArray());

        // The card signs the student in, even on a computer where someone else was signed in.
        $this->get('/card/'.$code)->assertRedirect();
        $this->assertAuthenticatedAs($student->fresh());
        auth()->logout();
        $this->get('/card/EXNOTAREALCODE000000000')->assertRedirect(route('login'));
        $this->assertGuest();

        // A new printing renews the code: the old card stops working.
        $this->actingAs($admin)->post(route('exams.manage.groups.cards', $group));
        auth()->logout();
        $this->get('/card/'.$code);
        $this->assertGuest();

        // Staff cannot sign in with a card.
        $admin->forceFill(['card_code' => 'EX'.str_repeat('A', 22)])->save();
        $this->get('/card/EX'.str_repeat('A', 22));
        $this->assertGuest();

        // The invigilator scans the card: present.
        $exam = $this->exam();
        $exam->groups()->attach($group->id);
        $fresh = $student->fresh()->card_code;
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.scan', $exam), ['code' => url('/card/'.$fresh)])->assertJson(['ok' => true, 'name' => 'زينب']);
        $this->assertTrue((bool) DB::table('exam_attendance')->where('exam_id', $exam->id)->where('user_id', $student->id)->value('present'));
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.scan', $exam), ['code' => 'nonsense'])->assertStatus(422);
        $outsider = User::factory()->create();
        $outsider->renewCardCode();
        $this->actingAs($this->teacher)->postJson(route('exams.monitor.scan', $exam), ['code' => $outsider->card_code])->assertStatus(422);
    }

    public function test_every_answer_change_is_logged_for_the_teacher(): void
    {
        $exam = $this->exam();
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        [$single, $short] = $exam->questions()->orderBy('position')->get()->all();
        $wrong = $single->choices()->where('is_correct', false)->value('id');
        $right = $single->choices()->where('is_correct', true)->value('id');

        $save = fn ($q, $r) => $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $q->id, 'response' => $r])->assertOk();
        $save($single, ['choice' => $wrong]);
        $save($single, ['choice' => $right]);
        $save($single, ['choice' => $right]);   // unchanged: not logged again
        $save($short, ['text' => 'بغداد']);
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $short->id, 'flagged' => true])->assertOk();
        $this->assertSame(3, AnswerLog::where('exam_attempt_id', $attempt->id)->count());

        $entries = $this->actingAs($this->teacher)->getJson(route('exams.manage.attempts.log', $attempt))->assertOk()->json('entries');
        $this->assertSame(['CO2', 'H2O', 'بغداد'], array_column($entries, 'answer'));
        $this->assertSame([1, 1, 2], array_column($entries, 'question'));
        $this->assertSame('127.0.0.1', $entries[0]['ip']);

        // Not for students, nor other teachers.
        $this->assertContains($this->actingAs($student)->getJson(route('exams.manage.attempts.log', $attempt))->status(), [403, 404]);
        $this->actingAs(User::factory()->create(['is_examiner' => true]))->getJson(route('exams.manage.attempts.log', $attempt))->assertNotFound();
    }
}
