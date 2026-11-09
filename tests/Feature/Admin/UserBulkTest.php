<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class UserBulkTest extends TestCase
{
    use RefreshDatabase;

    public function test_select_all_switch_on_and_off_export_and_print(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true, 'name' => 'المدير']);
        $students = collect(range(1, 5))->map(fn ($i) => User::factory()->create(['name' => "طالب {$i}", 'student_number' => "S{$i}"]));

        $this->assertCount(5, $this->actingAs($admin)->getJson(route('admin.users.ids', ['role' => 'user']))->json('ids'));
        $this->assertSame([$students[2]->id], $this->actingAs($admin)->getJson(route('admin.users.ids', ['q' => 'S3']))->json('ids'));

        // Ticked ones off; then all of a search back on.
        $this->actingAs($admin)->post(route('admin.users.bulk-status'), ['is_active' => false, 'ids' => [$students[0]->id, $students[1]->id, $admin->id]])->assertSessionHas('success', 'أُوقف 2 حساباً.');
        $this->assertFalse($students[0]->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_active, 'never yourself');
        $this->actingAs($admin)->post(route('admin.users.bulk-status'), ['is_active' => false, 'all' => 1, 'role' => 'user'])->assertSessionHas('success', 'أُوقف 3 حساباً.');
        $this->assertSame(0, User::where('role', 'user')->where('is_active', true)->count());
        $this->actingAs($admin)->post(route('admin.users.bulk-status'), ['is_active' => true, 'all' => 1, 'q' => 'طالب'])->assertSessionHas('success', 'فُعّل 5 حساباً.');

        // Export and print: every result, or a kept selection.
        $csv = $this->actingAs($admin)->get(route('admin.users.export', ['all' => 1, 'role' => 'user']))->assertOk()->streamedContent();
        $this->assertStringContainsString('الرقم الجامعي', $csv);
        $this->assertSame(6, substr_count(trim($csv), "\n") + 1);
        $sel = $this->actingAs($admin)->postJson(route('admin.users.selection'), ['ids' => [$students[4]->id]])->json('sel');
        $this->actingAs($admin)->get(route('admin.users.print', ['sel' => $sel]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/Users/Print')->has('users', 1)->where('users.0.number', 'S5'));
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('groups'));

        $this->actingAs($students[0])->post(route('admin.users.bulk-status'), ['is_active' => false, 'all' => 1])->assertForbidden();
    }
}
