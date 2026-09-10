<?php

namespace Tests\Feature\Api;

use App\Models\File;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistApiTest extends TestCase
{
    use RefreshDatabase;

    private function videoFile(User $user): File
    {
        return File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4']);
    }

    public function test_a_playlist_can_be_created_with_an_initial_file_via_the_api(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/playlists', [
            'name' => 'دروس',
            'file_id' => $file->id,
        ]);

        $response->assertCreated();
        $playlistId = $response->json('playlist.id');
        $this->assertSame(1, $response->json('playlist.items_count') ?? count($response->json('playlist.items')));
        $this->assertSame($file->id, $response->json('playlist.items.0.file.id'));

        $this->assertDatabaseHas('playlist_items', ['playlist_id' => $playlistId, 'file_id' => $file->id]);
    }

    public function test_playlists_are_listed_for_the_authenticated_user_via_the_api(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Playlist::create(['user_id' => $user->id, 'name' => 'قائمتي']);
        Playlist::create(['user_id' => $other->id, 'name' => 'قائمة غيري']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/playlists')->assertOk();

        $this->assertCount(1, $response->json('playlists'));
        $this->assertSame('قائمتي', $response->json('playlists.0.name'));
    }

    public function test_a_file_can_be_added_to_and_removed_from_a_playlist_via_the_api(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'قائمتي']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/playlists/{$playlist->id}/items", ['file_id' => $file->id])
            ->assertOk();

        $item = PlaylistItem::where('playlist_id', $playlist->id)->where('file_id', $file->id)->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/playlists/{$playlist->id}/items/{$item->id}")
            ->assertOk();

        $this->assertDatabaseMissing('playlist_items', ['id' => $item->id]);
    }

    public function test_a_non_media_file_cannot_be_added_via_the_api(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'application/pdf']);
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'قائمتي']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/playlists/{$playlist->id}/items", ['file_id' => $file->id])
            ->assertStatus(422);
    }

    public function test_the_same_file_cannot_be_added_twice_via_the_api(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'قائمتي']);
        PlaylistItem::create(['playlist_id' => $playlist->id, 'file_id' => $file->id, 'order' => 0]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/playlists/{$playlist->id}/items", ['file_id' => $file->id])
            ->assertStatus(422);
    }

    public function test_playlist_items_can_be_reordered_via_the_api(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'قائمتي']);
        $first = PlaylistItem::create(['playlist_id' => $playlist->id, 'file_id' => $this->videoFile($user)->id, 'order' => 0]);
        $second = PlaylistItem::create(['playlist_id' => $playlist->id, 'file_id' => $this->videoFile($user)->id, 'order' => 1]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/playlists/{$playlist->id}/reorder", ['order' => [$second->id, $first->id]])
            ->assertOk();

        $this->assertSame(0, PlaylistItem::find($second->id)->order);
        $this->assertSame(1, PlaylistItem::find($first->id)->order);
    }

    public function test_a_playlist_can_be_renamed_and_deleted_via_the_api(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'اسم قديم']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/playlists/{$playlist->id}", ['name' => 'اسم جديد'])
            ->assertOk()
            ->assertJsonPath('playlist.name', 'اسم جديد');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/playlists/{$playlist->id}")
            ->assertOk();

        $this->assertDatabaseMissing('playlists', ['id' => $playlist->id]);
    }

    public function test_the_picker_endpoint_flags_which_playlists_contain_the_given_file(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $playlistWithFile = Playlist::create(['user_id' => $user->id, 'name' => 'تحتوي الملف']);
        PlaylistItem::create(['playlist_id' => $playlistWithFile->id, 'file_id' => $file->id, 'order' => 0]);
        Playlist::create(['user_id' => $user->id, 'name' => 'لا تحتوي']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/playlists/picker?file_id={$file->id}")
            ->assertOk();

        $this->assertCount(2, $response->json('playlists'));
        $flagged = collect($response->json('playlists'))->firstWhere('id', $playlistWithFile->id);
        $this->assertTrue($flagged['contains_file']);
    }

    public function test_a_user_cannot_view_or_modify_another_users_playlist_via_the_api(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $playlist = Playlist::create(['user_id' => $owner->id, 'name' => 'خاصة']);

        $this->actingAs($intruder, 'sanctum')->getJson("/api/v1/playlists/{$playlist->id}")->assertForbidden();
        $this->actingAs($intruder, 'sanctum')->putJson("/api/v1/playlists/{$playlist->id}", ['name' => 'تغيير'])->assertForbidden();
        $this->actingAs($intruder, 'sanctum')->deleteJson("/api/v1/playlists/{$playlist->id}")->assertForbidden();
    }

    public function test_the_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/playlists')->assertUnauthorized();
    }
}
