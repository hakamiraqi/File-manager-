<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'admin',
            'is_master_admin' => true,
        ], $attributes));
    }

    public function test_a_non_master_admin_cannot_create_a_new_admin_account_even_with_manage_users_capability(): void
    {
        $limitedAdmin = $this->admin(['is_master_admin' => false, 'can_manage_users' => true]);

        $this->actingAs($limitedAdmin)->post(route('admin.users.store'), [
            'name' => 'Sneaky Admin',
            'email' => 'sneaky@example.com',
            'password' => 'password123',
            'role' => 'admin',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_a_non_master_admin_cannot_promote_an_existing_user_to_admin(): void
    {
        $limitedAdmin = $this->admin(['is_master_admin' => false, 'can_manage_users' => true]);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($limitedAdmin)
            ->put(route('admin.users.update', $target), ['role' => 'admin'])
            ->assertStatus(403);

        $this->assertSame('user', $target->fresh()->role);
    }

    public function test_the_master_admin_can_create_a_new_admin_account(): void
    {
        $master = $this->admin();

        $this->actingAs($master)->post(route('admin.users.store'), [
            'name' => 'New Admin',
            'email' => 'newadmin2@example.com',
            'password' => 'password123',
            'role' => 'admin',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'newadmin2@example.com', 'role' => 'admin']);
    }
}
