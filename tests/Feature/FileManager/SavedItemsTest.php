<?php

namespace Tests\Feature\FileManager;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_files_and_folders_are_saved_and_unsaved_like_a_bookmark(): void
    {
        $user = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $user->id]);
        $file = File::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson(route('saved.toggle'), ['type' => 'file', 'id' => $file->id])->assertJson(['saved' => true]);
        $this->actingAs($user)->post(route('saved.toggle'), ['type' => 'folder', 'id' => $folder->id])->assertRedirect();

        $this->actingAs($user)->get(route('saved.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Saved/Index')
                ->has('saved', 2)
                ->where('saved.0.type', 'folder')
                ->where('saved.1.item.id', $file->id)
                ->where('savedIds.files', [$file->id])
                ->where('savedIds.folders', [$folder->id]));

        $this->actingAs($user)->postJson(route('saved.toggle'), ['type' => 'file', 'id' => $file->id])->assertJson(['saved' => false]);
        $this->assertDatabaseCount('saved_items', 1);

        // Deleted items drop out of the list.
        $folder->delete();
        $this->actingAs($user)->get(route('saved.index'))->assertInertia(fn ($page) => $page->has('saved', 0));
    }

    public function test_nobody_can_save_what_they_cannot_see(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $file = File::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)->postJson(route('saved.toggle'), ['type' => 'file', 'id' => $file->id])->assertForbidden();
        $this->assertDatabaseCount('saved_items', 0);
    }

    public function test_downloaded_files_are_listed_and_can_be_cleared_without_losing_the_log(): void
    {
        $user = User::factory()->create();
        $a = File::factory()->create(['user_id' => $user->id, 'name' => 'a.pdf']);
        $b = File::factory()->create(['user_id' => $user->id, 'name' => 'b.pdf']);
        foreach ([[$a, '2026-11-01 08:00:00'], [$a, '2026-11-03 08:00:00'], [$b, '2026-11-02 08:00:00']] as [$f, $at]) {
            ActivityLog::forceCreate(['user_id' => $user->id, 'action' => 'file.downloaded', 'subject_type' => 'file', 'subject_id' => $f->id, 'created_at' => $at]);
        }

        $this->actingAs($user)->get(route('saved.index'))
            ->assertInertia(fn ($page) => $page
                ->has('downloads', 2)
                ->where('downloads.0.item.name', 'a.pdf')
                ->where('downloads.0.times', 2)
                ->where('downloads.1.item.name', 'b.pdf'));

        $this->actingAs($user)->delete(route('saved.downloads.forget'), ['id' => $a->id])->assertRedirect();
        $this->actingAs($user)->get(route('saved.index'))->assertInertia(fn ($page) => $page->has('downloads', 1));

        $this->actingAs($user)->delete(route('saved.downloads.forget'))->assertRedirect();
        $this->actingAs($user)->get(route('saved.index'))->assertInertia(fn ($page) => $page->has('downloads', 0));
        $this->assertSame(3, ActivityLog::where('action', 'file.downloaded')->count());
    }
}
