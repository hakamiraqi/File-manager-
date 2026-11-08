<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_switches_a_user_off_and_on(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $user = User::factory()->create();
        $user->createToken('phone');

        $this->actingAs($admin)->patch(route('admin.users.status', $user), ['is_active' => false])->assertRedirect();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, $user->tokens()->count());

        $this->actingAs($admin)->patch(route('admin.users.status', $user), ['is_active' => true]);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_nobody_switches_off_themselves_or_the_master_admin(): void
    {
        $master = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'can_manage_users' => true]);

        $this->actingAs($master)->patch(route('admin.users.status', $master), ['is_active' => false])->assertStatus(422);
        $this->actingAs($admin)->patch(route('admin.users.status', $admin), ['is_active' => false])->assertStatus(422);
        $this->actingAs($admin)->patch(route('admin.users.status', $master), ['is_active' => false])->assertForbidden();
        $this->assertTrue($master->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_a_new_account_can_start_inactive(): void
    {
        $master = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);

        $this->actingAs($master)->post(route('admin.users.store'), [
            'name' => 'موظف جديد', 'email' => 'new@example.com', 'password' => 'Str0ng!Pass#2026', 'role' => 'user', 'is_active' => false,
        ])->assertSessionHasNoErrors();

        $this->assertFalse(User::where('email', 'new@example.com')->first()->is_active);
    }
}
