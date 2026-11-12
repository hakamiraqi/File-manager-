<?php

namespace Tests\Feature\Lectures;

use App\Models\File;
use App\Models\Folder;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LecturesTest extends TestCase
{
    use RefreshDatabase;

    private function video(User $owner, ?Folder $folder, string $name, array $extra = []): File
    {
        return File::factory()->create([
            'user_id' => $owner->id, 'folder_id' => $folder?->id, 'name' => $name,
            'extension' => 'mp4', 'mime_type' => 'video/mp4', ...$extra,
        ]);
    }

    public function test_each_user_sees_the_videos_of_the_folders_they_may_open_grouped_by_folder(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create();
        $stage = Folder::factory()->create(['user_id' => $admin->id, 'name' => 'المرحلة الثالثة']);
        $anatomy = Folder::factory()->create(['user_id' => $admin->id, 'parent_id' => $stage->id, 'name' => 'التشريح']);
        $hidden = Folder::factory()->create(['user_id' => $admin->id, 'name' => 'إداري']);
        $student->assignedFolders()->sync([$stage->id]);

        $this->video($admin, $anatomy, 'Lecture 10.mp4');
        $this->video($admin, $anatomy, 'Lecture 2.mp4');
        $this->video($admin, $anatomy, 'Lecture 1.MKV', ['extension' => 'mkv', 'mime_type' => 'application/octet-stream']);
        $this->video($admin, $hidden, 'secret.mp4');
        $this->video($student, null, 'mine.mp4');
        File::factory()->create(['user_id' => $admin->id, 'folder_id' => $anatomy->id, 'name' => 'notes.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf']);
        File::factory()->create(['user_id' => $admin->id, 'folder_id' => $anatomy->id, 'name' => 'talk.mp3', 'extension' => 'mp3', 'mime_type' => 'audio/mpeg']);

        $this->actingAs($student)->get(route('lectures.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Lectures/Index')
                ->has('videos', 4)
                // natural order: 1, 2, 10
                ->where('videos.0.name', 'Lecture 1.MKV')
                ->where('videos.0.mime_type', 'video/mp4')
                ->where('videos.1.name', 'Lecture 2.mp4')
                ->where('videos.2.name', 'Lecture 10.mp4')
                ->has('folders', 2)
                ->where('folders', fn ($folders) => collect($folders)->contains(fn ($f) => $f['name'] === 'التشريح' && $f['count'] === 3 && $f['path'] === ['المرحلة الثالثة'])
                    && collect($folders)->contains(fn ($f) => $f['id'] === null && $f['count'] === 1)));

        $this->actingAs($admin)->get(route('lectures.index'))
            ->assertInertia(fn (Assert $page) => $page->has('videos', 5));

        Sanctum::actingAs($student);
        $this->getJson('/api/v1/lectures')->assertOk()
            ->assertJsonCount(4, 'videos')
            ->assertJsonPath('videos.0.name', 'Lecture 1.MKV')
            ->assertJsonCount(2, 'folders');
    }

    public function test_a_student_can_add_a_staff_lecture_to_their_playlist_but_not_a_hidden_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create();
        $open = Folder::factory()->create(['user_id' => $admin->id]);
        $closed = Folder::factory()->create(['user_id' => $admin->id]);
        $student->assignedFolders()->sync([$open->id]);
        $lecture = $this->video($admin, $open, 'lecture.mp4');
        $secret = $this->video($admin, $closed, 'secret.mp4');
        $pdf = File::factory()->create(['user_id' => $admin->id, 'folder_id' => $open->id, 'extension' => 'pdf', 'mime_type' => 'application/pdf']);
        $playlist = Playlist::create(['user_id' => $student->id, 'name' => 'مراجعة']);

        $this->actingAs($student)->postJson(route('playlists.items.store', $playlist), ['file_id' => $lecture->id])->assertSuccessful();
        $this->assertTrue($playlist->items()->where('file_id', $lecture->id)->exists());

        $this->postJson(route('playlists.items.store', $playlist), ['file_id' => $secret->id])->assertForbidden();
        $this->postJson(route('playlists.items.store', $playlist), ['file_id' => $pdf->id])->assertUnprocessable();
        $this->postJson(route('playlists.items.store', $playlist), ['file_id' => $lecture->id])->assertUnprocessable(); // already there

        // Creating a new list with a staff lecture works too.
        $this->post(route('playlists.store'), ['name' => 'جديدة', 'file_id' => $lecture->id])->assertRedirect();
        $this->assertTrue(Playlist::where('name', 'جديدة')->first()->items()->where('file_id', $lecture->id)->exists());
    }
}
