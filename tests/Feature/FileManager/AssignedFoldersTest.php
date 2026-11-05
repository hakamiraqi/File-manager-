<?php

namespace Tests\Feature\FileManager;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignedFoldersTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_assigned_folder_and_its_contents_are_usable_by_the_user(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create(['role' => 'editor']);
        $stranger = User::factory()->create();

        $root = Folder::factory()->create(['user_id' => $owner->id, 'name' => 'الدراسات الأولية']);
        $child = Folder::factory()->create(['user_id' => $owner->id, 'parent_id' => $root->id, 'name' => 'المرحلة الأولى']);
        $other = Folder::factory()->create(['user_id' => $owner->id, 'name' => 'خاص']);
        $editor->assignedFolders()->sync([$root->id]);

        // Shown at the top of the editor's files; the owner's other folder is not.
        $this->actingAs($editor)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('folders', fn ($f) => collect($f)->pluck('id')->all() === [$root->id]));

        // Inside it the editor sees the owner's sub-folder, and can open it.
        $this->actingAs($editor)->get(route('dashboard', ['folder' => $child->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('breadcrumbs.0.id', $root->id));

        // Can add a folder there; the owner then sees it too.
        $this->actingAs($editor)->post(route('folders.store'), ['name' => 'جديد', 'parent_id' => $child->id])->assertRedirect();
        $this->assertDatabaseHas('folders', ['parent_id' => $child->id, 'name' => 'جديد', 'user_id' => $editor->id]);
        $this->actingAs($owner)->get(route('dashboard', ['folder' => $child->id]))
            ->assertInertia(fn ($page) => $page->where('folders.0.name', 'جديد'));

        // Not assigned: 404.
        $this->actingAs($editor)->get(route('dashboard', ['folder' => $other->id]))->assertNotFound();
        $this->actingAs($stranger)->get(route('dashboard', ['folder' => $root->id]))->assertNotFound();
    }

    public function test_files_in_an_assigned_folder_can_be_renamed_by_the_user(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create(['role' => 'editor']);
        $folder = Folder::factory()->create(['user_id' => $owner->id]);
        $file = File::factory()->create(['user_id' => $owner->id, 'folder_id' => $folder->id, 'name' => 'a.pdf']);

        $this->actingAs($editor)->put(route('files.update', $file), ['name' => 'b.pdf'])->assertForbidden();

        $editor->assignedFolders()->sync([$folder->id]);
        $this->actingAs($editor->fresh())->put(route('files.update', $file), ['name' => 'b.pdf'])->assertRedirect();
        $this->assertSame('b.pdf', $file->fresh()->name);
    }
}
