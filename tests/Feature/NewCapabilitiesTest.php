<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\IpRule;
use App\Models\RolePermission;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NewCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_contributor_cannot_delete_their_own_file(): void
    {
        Storage::fake(config('filenest.disk'));
        $contributor = User::factory()->create(['role' => 'contributor']);
        $file = File::factory()->create(['user_id' => $contributor->id]);

        $this->actingAs($contributor)
            ->delete(route('files.destroy', $file))
            ->assertForbidden();

        $this->assertDatabaseHas('files', ['id' => $file->id, 'deleted_at' => null]);
    }

    public function test_a_contributor_can_still_upload_and_share(): void
    {
        Storage::fake(config('filenest.disk'));
        $contributor = User::factory()->create(['role' => 'contributor']);
        $file = File::factory()->create(['user_id' => $contributor->id]);

        $this->actingAs($contributor)
            ->post(route('shares.store'), ['shareable_type' => 'file', 'shareable_id' => $file->id])
            ->assertRedirect();

        $this->assertDatabaseHas('shares', ['shareable_type' => 'file', 'shareable_id' => $file->id]);
    }

    public function test_disabling_a_permission_takes_effect_immediately(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create(['role' => 'user']);
        $file = File::factory()->create(['user_id' => $user->id]);

        RolePermission::where('role', 'user')->where('action', 'delete')->update(['allowed' => false]);

        $this->actingAs($user)
            ->delete(route('files.destroy', $file))
            ->assertForbidden();
    }

    public function test_a_limited_admin_cannot_reach_a_page_outside_their_capability(): void
    {
        $limitedAdmin = User::factory()->create([
            'role' => 'admin',
            'is_master_admin' => false,
            'can_manage_users' => true,
            'can_manage_settings' => false,
        ]);

        $this->actingAs($limitedAdmin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($limitedAdmin)->get(route('admin.settings.index'))->assertForbidden();
    }

    public function test_one_time_download_share_is_revoked_after_first_download(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'path' => 'once.txt']);
        Storage::disk(config('filenest.disk'))->put('once.txt', 'hello');

        $share = Share::factory()->create([
            'user_id' => $user->id,
            'shareable_type' => 'file',
            'shareable_id' => $file->id,
            'max_downloads' => 1,
            'one_time_download' => true,
        ]);

        $this->get(route('share.public.download', $share->token))->assertOk();
        $this->get(route('share.public.download', $share->token))->assertStatus(410);

        $this->assertNotNull($share->fresh()->revoked_at);
    }

    public function test_short_code_redirects_to_the_full_share_page(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        $share = Share::factory()->create([
            'user_id' => $user->id,
            'shareable_type' => 'file',
            'shareable_id' => $file->id,
        ]);

        $this->get(route('share.public.short', $share->short_code))
            ->assertRedirect(route('share.public.show', $share->token));
    }

    public function test_ip_blacklist_blocks_matching_visitors(): void
    {
        \App\Models\Setting::set('ip_filter_mode', 'blacklist');
        IpRule::create(['type' => 'blacklist', 'ip_or_cidr' => '127.0.0.1']);

        $this->get(route('login'))->assertForbidden();
    }

    public function test_guest_browsing_is_hidden_until_enabled_and_folder_is_public(): void
    {
        $owner = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $owner->id, 'is_public' => true]);

        $this->get(route('guest.show', $folder->id))->assertNotFound();

        \App\Models\Setting::set('guest_access_enabled', true);

        $this->get(route('guest.show', $folder->id))->assertOk();
    }

    public function test_remote_upload_rejects_a_url_pointing_at_a_private_ip(): void
    {
        \App\Models\Setting::set('remote_upload_enabled', true);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('uploads.remote'), ['url' => 'http://127.0.0.1/secret'])
            ->assertStatus(422);
    }

    public function test_extension_reject_list_blocks_matching_uploads(): void
    {
        \App\Models\Setting::setMany(['extension_filter_mode' => 'reject', 'extension_list' => 'exe,bat']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('uploads.init'), ['name' => 'virus.exe', 'size' => 10])
            ->assertStatus(422);
    }

    public function test_registration_uses_the_configured_default_role(): void
    {
        \App\Models\Setting::setMany(['registration_enabled' => '1', 'registration_role' => 'contributor']);

        $this->post(route('register'), [
            'name' => 'Test User',
            'email' => 'newbie@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('users', ['email' => 'newbie@example.com', 'role' => 'contributor']);
    }
}
