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

    public function test_login_cards_give_the_groups_students_new_passwords(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true, 'can_manage_users' => true]);
        $student = User::factory()->create();
        $teacher = User::factory()->create(['is_examiner' => true]);
        $group = Group::create(['user_id' => $admin->id, 'name' => 'أ']);
        $group->members()->attach([$student->id, $teacher->id]);
        $teacherHash = $teacher->password;

        $cards = $this->actingAs($admin)->post(route('exams.manage.groups.cards', $group))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Exams/Manage/Cards')->has('cards', 1))
            ->viewData('page')['props']['cards'];
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($cards[0]['password'], $student->fresh()->password));
        $this->assertSame($teacherHash, $teacher->fresh()->password);
        $this->actingAs($teacher)->post(route('exams.manage.groups.cards', $group))->assertNotFound();
    }
    public function test_a_student_is_in_one_group_and_is_picked_from_the_list(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        $a = Group::create(['user_id' => $teacher->id, 'name' => 'أ']);
        $b = Group::create(['user_id' => $teacher->id, 'name' => 'ب']);
        $ali = User::factory()->create(['name' => 'علي', 'student_number' => '2025001']);
        $sara = User::factory()->create(['name' => 'سارة']);
        User::factory()->create(['name' => 'أستاذ', 'is_examiner' => true]);

        // Only students, and only those in no group yet, are offered.
        $offered = $this->actingAs($teacher)->getJson(route('exams.manage.groups.candidates', $a))->assertOk()->json();
        $this->assertSame(2, $offered['total']);
        $this->assertSame(['علي'], collect($this->actingAs($teacher)->getJson(route('exams.manage.groups.candidates', [$a, 'q' => '2025']))->json('students'))->pluck('name')->all());

        $this->actingAs($teacher)->post(route('exams.manage.groups.members.store', $a), ['ids' => [$ali->id, $sara->id]])->assertSessionHasNoErrors();
        $this->assertSame(2, $a->members()->count());
        $this->assertSame(0, $this->actingAs($teacher)->getJson(route('exams.manage.groups.candidates', $b))->json('total'));
        $this->actingAs($teacher)->get(route('exams.manage.groups', ['group' => $b->id]))->assertInertia(fn (AssertableInertia $p) => $p->where('unassigned', 0));

        // In another group: not added unless moved.
        $this->actingAs($teacher)->post(route('exams.manage.groups.members.store', $b), ['ids' => [$ali->id]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'علي'));
        $this->assertFalse($b->members()->whereKey($ali->id)->exists());
        $others = $this->actingAs($teacher)->getJson(route('exams.manage.groups.candidates', [$b, 'others' => 1]))->json('students');
        $this->assertEqualsCanonicalizing(['علي', 'سارة'], collect($others)->pluck('name')->all());
        $this->assertSame('أ', $others[0]['group']);
        $this->actingAs($teacher)->post(route('exams.manage.groups.members.store', $b), ['ids' => [$ali->id], 'move' => true])->assertSessionHasNoErrors();
        $this->assertTrue($b->members()->whereKey($ali->id)->exists());
        $this->assertFalse($a->members()->whereKey($ali->id)->exists());

        // Several taken out at once; they are offered again.
        $this->actingAs($teacher)->post(route('exams.manage.groups.members.remove', $a), ['ids' => [$sara->id]])->assertSessionHasNoErrors();
        $this->assertSame(0, $a->members()->count());
        $this->assertSame(['سارة'], collect($this->actingAs($teacher)->getJson(route('exams.manage.groups.candidates', $a))->json('students'))->pluck('name')->all());
    }
    public function test_students_imported_with_their_group_column(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true, 'can_manage_users' => true]);
        $old = Group::create(['user_id' => $admin->id, 'name' => 'قديمة']);
        $existing = User::factory()->create(['email' => 'old@x.test']);
        $old->members()->attach($existing->id);
        $csv = "الاسم,البريد,المجموعة,المرحلة\nعلي,ali@x.test,شعبة أ,الأولى\nسارة,sara@x.test,شعبة ب,الأولى\nقديم,old@x.test,شعبة أ,\nزيد,zaid@x.test,,\n";

        $this->actingAs($admin)->post(route('exams.manage.students.import'), ['file' => UploadedFile::fake()->createWithContent('s.csv', $csv)])->assertOk();
        $a = Group::where('name', 'شعبة أ')->firstOrFail();
        $this->assertSame('الأولى', $a->stage);
        $this->assertEqualsCanonicalizing(['ali@x.test', 'old@x.test'], $a->members()->pluck('email')->all());
        $this->assertSame(['sara@x.test'], Group::where('name', 'شعبة ب')->firstOrFail()->members()->pluck('email')->all());
        $this->assertSame(0, $old->members()->count());
        $this->assertFalse(User::where('email', 'zaid@x.test')->firstOrFail()->examGroups()->exists());
    }
}
