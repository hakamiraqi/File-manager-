<?php

namespace Tests\Feature\Api;

use App\Models\IpRule;
use App\Models\RolePermission;
use App\Models\Setting;
use App\Models\TranslationOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'admin',
            'is_master_admin' => true,
        ], $attributes));
    }

    public function test_a_non_admin_is_forbidden_from_every_admin_endpoint(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/settings')->assertStatus(403);
    }

    public function test_admin_dashboard_returns_platform_statistics(): void
    {
        $admin = $this->admin();
        User::factory()->count(3)->create();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/dashboard');

        $response->assertOk();
        $response->assertJsonPath('stats.users', 4);
        $response->assertJsonStructure(['stats', 'recent_activity', 'top_users']);
    }

    public function test_admin_can_list_create_update_and_delete_users(): void
    {
        $admin = $this->admin();

        $create = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'Managed User',
            'email' => 'managed@example.com',
            'password' => 'password123',
            'role' => 'user',
        ]);
        $create->assertCreated();
        $userId = $create->json('user.id');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonFragment(['email' => 'managed@example.com']);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/users/{$userId}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('user.is_active', false);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/users/{$userId}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_a_non_master_admin_cannot_grant_capabilities_when_creating_a_user(): void
    {
        $limitedAdmin = $this->admin(['is_master_admin' => false, 'can_manage_users' => true]);

        $response = $this->actingAs($limitedAdmin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'New Editor',
            'email' => 'neweditor@example.com',
            'password' => 'password123',
            'role' => 'editor',
            'can_manage_users' => true,
        ]);

        $response->assertCreated();
        $this->assertFalse((bool) $response->json('user.can_manage_users'));
    }

    public function test_a_non_master_admin_cannot_create_a_new_admin_account_even_with_manage_users_capability(): void
    {
        $limitedAdmin = $this->admin(['is_master_admin' => false, 'can_manage_users' => true]);

        $response = $this->actingAs($limitedAdmin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'Sneaky Admin',
            'email' => 'sneaky@example.com',
            'password' => 'password123',
            'role' => 'admin',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_a_non_master_admin_cannot_promote_an_existing_user_to_admin(): void
    {
        $limitedAdmin = $this->admin(['is_master_admin' => false, 'can_manage_users' => true]);
        $target = User::factory()->create(['role' => 'user']);

        $this->actingAs($limitedAdmin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$target->id}", ['role' => 'admin'])
            ->assertStatus(403);

        $this->assertSame('user', $target->fresh()->role);
    }

    public function test_the_master_admin_can_create_a_new_admin_account(): void
    {
        $master = $this->admin();

        $this->actingAs($master, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'New Admin',
            'email' => 'newadmin2@example.com',
            'password' => 'password123',
            'role' => 'admin',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'newadmin2@example.com', 'role' => 'admin']);
    }

    public function test_an_admin_cannot_delete_their_own_account_via_the_admin_endpoint(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/users/{$admin->id}")
            ->assertStatus(422);
    }

    public function test_the_master_admin_account_cannot_be_deleted_by_another_admin(): void
    {
        $master = $this->admin();
        $otherAdmin = $this->admin(['is_master_admin' => false]);

        $this->actingAs($otherAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/users/{$master->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_read_and_update_site_settings(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonStructure(['settings', 'skins', 'locales', 'roles']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/settings', [
            'site_name' => 'My Custom Portal',
            'registration_role' => 'user',
            'default_locale' => 'ar',
            'active_skin' => 'indigo',
            'default_quota_mb' => 1024,
            'max_upload_mb' => 512,
            'extension_filter_mode' => 'reject',
            'max_zip_files' => 500,
            'content_indexing_max_mb' => 10,
            'thumbnail_max_px' => 300,
        ]);

        $response->assertOk();
        $this->assertSame('My Custom Portal', Setting::get('site_name'));
    }

    public function test_admin_can_read_and_update_the_role_permission_matrix(): void
    {
        $admin = $this->admin();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/permissions')
            ->assertOk()
            ->assertJsonStructure(['matrix', 'roles', 'actions']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/permissions', [
            'matrix' => ['user' => ['download' => false]],
        ])->assertOk();

        $this->assertFalse(RolePermission::allows('user', 'download'));
    }

    public function test_admin_can_manage_ip_rules(): void
    {
        $admin = $this->admin();

        $create = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/security/ip-rules', [
            'type' => 'blacklist',
            'ip_or_cidr' => '10.0.0.1',
        ]);
        $create->assertCreated();
        $ruleId = $create->json('ip_rule.id');

        $this->assertDatabaseHas('ip_rules', ['id' => $ruleId]);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/security/ip-rules/{$ruleId}")->assertOk();
        $this->assertDatabaseMissing('ip_rules', ['id' => $ruleId]);
    }

    public function test_admin_can_read_and_update_security_settings(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/security')
            ->assertOk()
            ->assertJsonStructure(['settings', 'ip_rules', 'app_url']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/security', [
            'guest_access_enabled' => true,
            'ip_filter_mode' => 'none',
            'remote_upload_enabled' => true,
        ])->assertOk();

        $this->assertTrue((bool) Setting::get('guest_access_enabled'));
    }

    public function test_admin_can_read_and_update_mail_settings_without_ever_exposing_the_password(): void
    {
        $admin = $this->admin();
        Setting::set('mail_password', 'super-secret');

        $index = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/mail');
        $index->assertOk();
        $this->assertArrayNotHasKey('mail_password', $index->json('settings'));

        $update = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/mail', [
            'mail_host' => 'smtp.example.com',
            'mail_port' => 587,
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('mail_password', $update->json('settings'));
        $this->assertSame('super-secret', Setting::get('mail_password'));
    }

    public function test_admin_can_override_and_reset_a_translation_string(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/translations', [
            'locale' => 'ar',
            'key' => 'dashboard.title',
            'value' => 'لوحتي',
        ])->assertOk();

        $this->assertDatabaseHas('translation_overrides', ['locale' => 'ar', 'key' => 'dashboard.title', 'value' => 'لوحتي']);

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/translations', [
            'locale' => 'ar',
            'key' => 'dashboard.title',
        ])->assertOk();

        $this->assertDatabaseMissing('translation_overrides', ['locale' => 'ar', 'key' => 'dashboard.title']);
    }

    public function test_admin_can_view_the_activity_log(): void
    {
        $admin = $this->admin();
        \App\Models\ActivityLog::record('file.uploaded', 'test event');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/activity')
            ->assertOk()
            ->assertJsonStructure(['logs', 'meta']);
    }

    public function test_admin_can_view_analytics(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/analytics?days=7')
            ->assertOk()
            ->assertJsonPath('days', 7)
            ->assertJsonStructure(['labels', 'series', 'totals']);
    }

    public function test_a_limited_admin_without_view_statistics_capability_cannot_reach_analytics(): void
    {
        $limitedAdmin = $this->admin(['is_master_admin' => false, 'can_view_statistics' => false]);

        $this->actingAs($limitedAdmin, 'sanctum')
            ->getJson('/api/v1/admin/analytics')
            ->assertStatus(403);
    }
}
