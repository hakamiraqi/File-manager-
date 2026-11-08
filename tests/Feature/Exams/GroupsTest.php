<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GroupsTest extends TestCase
{
    use RefreshDatabase;

    private function saveExam(User $teacher, Exam $exam, array $settings = [])
    {
        return $this->actingAs($teacher)->put(route('exams.manage.update', $exam), $settings + [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => [['type' => 'short', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق؟'], 'settings' => ['answers' => ['بغداد']]]],
        ]);
    }

    public function test_an_exam_given_to_a_group_is_seen_by_its_members_only(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        [$inside, $outside] = User::factory()->count(2)->create();

        $this->actingAs($teacher)->post(route('exams.manage.groups.store'), ['name' => 'المرحلة الثانية — أ', 'stage' => 'الثانية'])->assertRedirect();
        $group = Group::firstOrFail();
        $this->actingAs($teacher)->post(route('exams.manage.groups.members.store', $group), ['emails' => "{$inside->email}\nnobody@x.test"])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'nobody@x.test'));
        $this->assertTrue($group->members()->whereKey($inside->id)->exists());

        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->saveExam($teacher, $exam, ['group_ids' => [$group->id]])->assertSessionHasNoErrors();

        $this->actingAs($inside)->get(route('exams.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('exams', 1));
        $this->actingAs($outside)->get(route('exams.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('exams', 0));
        $this->actingAs($outside)->get(route('exams.show', $exam))->assertNotFound();
        $this->actingAs($outside)->post(route('exams.start', $exam))->assertNotFound();
        $this->actingAs($inside)->post(route('exams.start', $exam))->assertRedirect();
        $this->assertSame(1, Attempt::count());

        // A group that has exams cannot be deleted (they would open to everyone).
        $this->actingAs($teacher)->delete(route('exams.manage.groups.destroy', $group))->assertSessionHasErrors('group');
        $this->saveExam($teacher, $exam, ['group_ids' => []]);
        $this->actingAs($outside)->get(route('exams.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('exams', 1));
        $this->actingAs($teacher)->delete(route('exams.manage.groups.destroy', $group))->assertRedirect();
        $this->assertSame(0, Group::count());
    }

    public function test_only_the_maker_or_an_admin_changes_a_group(): void
    {
        $maker = User::factory()->create(['is_examiner' => true]);
        $other = User::factory()->create(['is_examiner' => true]);
        $group = Group::create(['user_id' => $maker->id, 'name' => 'أ']);

        $this->actingAs($other)->put(route('exams.manage.groups.update', $group), ['name' => 'ب'])->assertNotFound();
        $this->actingAs($other)->get(route('exams.manage.groups'))->assertInertia(fn (AssertableInertia $p) => $p->where('groups.0.editable', false));
        $this->actingAs(User::factory()->create())->get(route('exams.manage.groups'))->assertForbidden();
    }

    public function test_students_imported_into_a_group(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true, 'can_manage_users' => true]);
        $existing = User::factory()->create(['email' => 'old@x.test']);
        $group = Group::create(['user_id' => $admin->id, 'name' => 'أ']);
        $csv = "الاسم,البريد\nعلي,ali@x.test\nقديم,old@x.test\n";

        $this->actingAs($admin)->post(route('exams.manage.students.import'), ['file' => UploadedFile::fake()->createWithContent('s.csv', $csv), 'group_id' => $group->id])->assertOk();
        $this->assertEqualsCanonicalizing(['ali@x.test', 'old@x.test'], $group->members()->pluck('email')->all());
        $this->assertTrue($existing->examGroups()->exists());
    }

    public function test_the_hall_code_and_network(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        $student = User::factory()->create();
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->saveExam($teacher, $exam, ['allowed_ips' => '10.0.0.0/8 not-an-ip'])->assertSessionHasErrors('allowed_ips');
        $this->saveExam($teacher, $exam, ['access_code' => 'K7M2QX', 'allowed_ips' => "192.168.1.0/24\n10.1.2.3"])->assertSessionHasNoErrors();

        $this->actingAs($student)->get(route('exams.show', $exam))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('exam.needs_access_code', true)->where('exam.in_network', false));
        // From outside the hall's network: refused.
        $this->actingAs($student)->from(route('exams.show', $exam))->post(route('exams.start', $exam), ['access_code' => 'K7M2QX'])->assertSessionHasErrors('exam');

        $hall = ['REMOTE_ADDR' => '192.168.1.40'];
        $this->actingAs($student)->withServerVariables($hall)->from(route('exams.show', $exam))->post(route('exams.start', $exam), ['access_code' => 'WRONG1'])->assertSessionHasErrors('access_code');
        $this->actingAs($student)->withServerVariables($hall)->post(route('exams.start', $exam), ['access_code' => 'k7m2qx'])->assertRedirect();
        $attempt = Attempt::firstOrFail();
        $this->actingAs($student)->withServerVariables($hall)->get(route('exams.attempts.take', $attempt))->assertOk();
        $this->actingAs($student)->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get(route('exams.attempts.take', $attempt))->assertRedirect(route('exams.show', $exam));
    }
}
