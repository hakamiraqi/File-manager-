<?php

namespace Tests\Feature\Api;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileFolderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_browse_lists_the_folders_and_files_at_the_root(): void
    {
        $user = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $user->id]);
        $file = File::factory()->create(['user_id' => $user->id, 'folder_id' => null]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/browse')
            ->assertOk()
            ->assertJsonPath('folders.0.id', $folder->id)
            ->assertJsonPath('files.0.id', $file->id);
    }

    public function test_a_user_cannot_browse_into_another_users_folder(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/v1/browse?folder='.$folder->id)
            ->assertNotFound();
    }

    public function test_a_folder_can_be_created_via_the_api(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/folders', ['name' => 'Reports'])
            ->assertCreated()
            ->assertJsonPath('folder.name', 'Reports');

        $this->assertDatabaseHas('folders', ['user_id' => $user->id, 'name' => 'Reports']);
    }

    public function test_a_folder_can_be_renamed_via_the_api(): void
    {
        $user = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $user->id, 'name' => 'Old']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/folders/{$folder->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('folder.name', 'New');
    }

    public function test_a_user_cannot_update_another_users_folder(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder, 'sanctum')
            ->putJson("/api/v1/folders/{$folder->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_a_folder_can_be_trashed_via_the_api(): void
    {
        $user = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/folders/{$folder->id}")
            ->assertOk();

        $this->assertSoftDeleted('folders', ['id' => $folder->id]);
    }

    public function test_a_file_can_be_renamed_and_moved_via_the_api(): void
    {
        $user = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $user->id]);
        $file = File::factory()->create(['user_id' => $user->id, 'folder_id' => null]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/files/{$file->id}", ['name' => 'renamed.txt', 'folder_id' => $folder->id])
            ->assertOk()
            ->assertJsonPath('file.name', 'renamed.txt')
            ->assertJsonPath('file.folder_id', $folder->id);
    }

    public function test_a_file_can_be_downloaded_via_the_api_with_a_bearer_token(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'path' => 'a.txt', 'name' => 'a.txt']);
        Storage::disk(config('filenest.disk'))->put('a.txt', 'hello world');

        $token = $user->createToken('device')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/files/'.$file->id.'/download')
            ->assertOk();
    }

    public function test_a_file_can_be_trashed_via_the_api(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/files/{$file->id}")
            ->assertOk();

        $this->assertSoftDeleted('files', ['id' => $file->id]);
    }

    public function test_the_folder_tree_only_includes_the_authenticated_users_folders(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Folder::factory()->create(['user_id' => $user->id, 'name' => 'Mine']);
        Folder::factory()->create(['user_id' => $other->id, 'name' => 'Theirs']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/folders/tree');

        $response->assertOk();
        $names = collect($response->json())->pluck('name')->all();
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
    }
}
