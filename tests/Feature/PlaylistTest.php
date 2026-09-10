<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistTest extends TestCase
{
    use RefreshDatabase;

    private function videoFile(User $user): File
    {
        return File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4']);
    }

    public function test_user_can_create_a_playlist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('playlists.store'), ['name' => 'المفضلة'])->assertRedirect();

        $this->assertDatabaseHas('playlists', ['user_id' => $user->id, 'name' => 'المفضلة']);
    }

    public function test_user_can_create_a_playlist_with_an_initial_file(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);

        $this->actingAs($user)->post(route('playlists.store'), [
            'name' => 'دروس',
            'file_id' => $file->id,
        ])->assertRedirect();

        $playlist = Playlist::where('user_id', $user->id)->firstOrFail();
        $this->assertDatabaseHas('playlist_items', ['playlist_id' => $playlist->id, 'file_id' => $file->id]);
    }

    public function test_user_can_add_a_video_file_to_their_playlist(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'قائمتي']);

        $this->actingAs($user)->post(route('playlists.items.store', $playlist), ['file_id' => $file->id])->assertRedirect();

        $this->assertDatabaseHas('playlist_items', ['playlist_id' => $playlist->id, 'file_id' => $file->id, 'order' => 1]);
    }

    public function test_a_non_media_file_cannot_be_added_to_a_playlist(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'application/pdf']);
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'قائمتي']);

        $this->actingAs($user)->post(route('playlists.items.store', $playlist), ['file_id' => $file->id])
            ->assertSessionHasErrors('file_id');

        $this->assertDatabaseMissing('playlist_items', ['playlist_id' => $playlist->id, 'file_id' => $file->id]);
    }

    public function test_the_same_file_cannot_be_added_to_a_playlist_twice(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'قائمتي']);
        PlaylistItem::create(['playlist_id' => $playlist->id, 'file_id' => $file->id, 'order' => 1]);

        $this->actingAs($user)->post(route('playlists.items.store', $playlist), ['file_id' => $file->id])
            ->assertSessionHasErrors('file_id');

        $this->assertSame(1, PlaylistItem::where('playlist_id', $playlist->id)->count());
    }

    public function test_user_can_remove_an_item_from_their_playlist(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'قائمتي']);
        $item = PlaylistItem::create(['playlist_id' => $playlist->id, 'file_id' => $file->id, 'order' => 1]);

        $this->actingAs($user)->delete(route('playlists.items.destroy', [$playlist, $item]))->assertRedirect();

        $this->assertDatabaseMissing('playlist_items', ['id' => $item->id]);
    }

    public function test_user_can_reorder_playlist_items(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'قائمتي']);
        $first = PlaylistItem::create(['playlist_id' => $playlist->id, 'file_id' => $this->videoFile($user)->id, 'order' => 0]);
        $second = PlaylistItem::create(['playlist_id' => $playlist->id, 'file_id' => $this->videoFile($user)->id, 'order' => 1]);

        $this->actingAs($user)->put(route('playlists.reorder', $playlist), [
            'order' => [$second->id, $first->id],
        ])->assertRedirect();

        $this->assertSame(0, PlaylistItem::find($second->id)->order);
        $this->assertSame(1, PlaylistItem::find($first->id)->order);
    }

    public function test_user_can_rename_their_playlist(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'اسم قديم']);

        $this->actingAs($user)->put(route('playlists.update', $playlist), ['name' => 'اسم جديد'])->assertRedirect();

        $this->assertDatabaseHas('playlists', ['id' => $playlist->id, 'name' => 'اسم جديد']);
    }

    public function test_user_can_delete_their_playlist(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'للحذف']);

        $this->actingAs($user)->delete(route('playlists.destroy', $playlist))->assertRedirect();

        $this->assertDatabaseMissing('playlists', ['id' => $playlist->id]);
    }

    public function test_the_picker_endpoint_lists_playlists_and_flags_which_contain_the_given_file(): void
    {
        $user = User::factory()->create();
        $file = $this->videoFile($user);
        $playlistWithFile = Playlist::create(['user_id' => $user->id, 'name' => 'تحتوي الملف']);
        PlaylistItem::create(['playlist_id' => $playlistWithFile->id, 'file_id' => $file->id, 'order' => 0]);
        Playlist::create(['user_id' => $user->id, 'name' => 'لا تحتوي']);

        $response = $this->actingAs($user)
            ->getJson(route('playlists.picker', ['file_id' => $file->id]))
            ->assertOk()
            ->json();

        $this->assertCount(2, $response['playlists']);
        $flagged = collect($response['playlists'])->firstWhere('id', $playlistWithFile->id);
        $this->assertTrue($flagged['contains_file']);
    }

    public function test_a_user_cannot_view_another_users_playlist(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $playlist = Playlist::create(['user_id' => $owner->id, 'name' => 'خاصة']);

        $this->actingAs($intruder)->get(route('playlists.show', $playlist))->assertForbidden();
    }

    public function test_a_user_cannot_update_another_users_playlist(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $playlist = Playlist::create(['user_id' => $owner->id, 'name' => 'خاصة']);

        $this->actingAs($intruder)->put(route('playlists.update', $playlist), ['name' => 'تغيير'])->assertForbidden();
    }

    public function test_a_user_cannot_delete_another_users_playlist(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $playlist = Playlist::create(['user_id' => $owner->id, 'name' => 'خاصة']);

        $this->actingAs($intruder)->delete(route('playlists.destroy', $playlist))->assertForbidden();
    }

    public function test_a_user_cannot_add_items_to_another_users_playlist(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $playlist = Playlist::create(['user_id' => $owner->id, 'name' => 'خاصة']);
        $file = $this->videoFile($intruder);

        $this->actingAs($intruder)->post(route('playlists.items.store', $playlist), ['file_id' => $file->id])->assertForbidden();
    }

    public function test_a_user_cannot_add_another_users_file_to_their_own_playlist(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $playlist = Playlist::create(['user_id' => $user->id, 'name' => 'قائمتي']);
        $foreignFile = $this->videoFile($otherUser);

        $this->actingAs($user)->post(route('playlists.items.store', $playlist), ['file_id' => $foreignFile->id])->assertNotFound();
    }
}
