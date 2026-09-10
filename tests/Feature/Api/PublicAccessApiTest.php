<?php

namespace Tests\Feature\Api;

use App\Models\File;
use App\Models\Folder;
use App\Models\Setting;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicAccessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_share_metadata_is_visible_to_anyone_without_a_token(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'name' => 'report.pdf']);
        $share = Share::factory()->create([
            'user_id' => $user->id,
            'shareable_type' => 'file',
            'shareable_id' => $file->id,
        ]);

        $response = $this->getJson("/api/v1/public/shares/{$share->token}");

        $response->assertOk();
        $response->assertJsonPath('name', 'report.pdf');
        $response->assertJsonPath('requires_password', false);
    }

    public function test_a_short_code_resolves_to_its_full_token(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        $share = Share::factory()->create([
            'user_id' => $user->id,
            'shareable_type' => 'file',
            'shareable_id' => $file->id,
        ]);

        $this->getJson("/api/v1/public/shares/short/{$share->short_code}")
            ->assertOk()
            ->assertJsonPath('token', $share->token);
    }

    public function test_downloading_a_password_protected_share_requires_the_correct_password(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'path' => 'a.txt']);
        Storage::disk(config('filenest.disk'))->put('a.txt', 'secret content');

        $share = Share::factory()->create([
            'user_id' => $user->id,
            'shareable_type' => 'file',
            'shareable_id' => $file->id,
            'password' => Hash::make('unlock-me'),
        ]);

        $this->postJson("/api/v1/public/shares/{$share->token}/download", [])
            ->assertStatus(422);

        $this->postJson("/api/v1/public/shares/{$share->token}/download", ['password' => 'wrong'])
            ->assertStatus(403);

        $this->postJson("/api/v1/public/shares/{$share->token}/download", ['password' => 'unlock-me'])
            ->assertOk();
    }

    public function test_an_expired_share_cannot_be_downloaded(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        $share = Share::factory()->create([
            'user_id' => $user->id,
            'shareable_type' => 'file',
            'shareable_id' => $file->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->postJson("/api/v1/public/shares/{$share->token}/download")
            ->assertStatus(410);
    }

    public function test_guest_folder_browsing_is_hidden_until_enabled_and_folder_is_public(): void
    {
        $owner = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $owner->id, 'is_public' => true]);

        $this->getJson("/api/v1/public/folders/{$folder->id}")->assertNotFound();

        Setting::set('guest_access_enabled', true);

        $this->getJson("/api/v1/public/folders/{$folder->id}")
            ->assertOk()
            ->assertJsonPath('current_folder.id', $folder->id);
    }

    public function test_guest_file_entries_point_at_the_unauthenticated_guest_routes_not_the_bearer_protected_ones(): void
    {
        Storage::fake(config('filenest.disk'));
        Setting::set('guest_access_enabled', true);
        \App\Models\RolePermission::updateOrCreate(['role' => 'guest', 'action' => 'view'], ['allowed' => true]);

        $owner = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $owner->id, 'is_public' => true]);
        $file = File::factory()->create(['user_id' => $owner->id, 'folder_id' => $folder->id, 'path' => 'x.txt']);
        Storage::disk(config('filenest.disk'))->put('x.txt', 'x');

        $response = $this->getJson("/api/v1/public/folders/{$folder->id}");

        $response->assertOk();
        $downloadUrl = $response->json('files.0.download_url');
        $this->assertStringContainsString('/api/v1/public/files/', $downloadUrl);
    }

    public function test_remote_upload_rejects_a_url_pointing_at_a_private_ip(): void
    {
        Setting::set('remote_upload_enabled', true);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/uploads/remote', ['url' => 'http://127.0.0.1/secret'])
            ->assertStatus(422);
    }

    public function test_remote_upload_is_a_404_when_the_feature_is_disabled(): void
    {
        Setting::set('remote_upload_enabled', false);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/uploads/remote', ['url' => 'https://example.com/file.txt'])
            ->assertNotFound();
    }
}
