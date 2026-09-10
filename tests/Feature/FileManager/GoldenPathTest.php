<?php

namespace Tests\Feature\FileManager;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoldenPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_a_folder(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('folders.store'), ['name' => 'مستندات'])
            ->assertRedirect();

        $this->assertDatabaseHas('folders', [
            'user_id' => $user->id,
            'name' => 'مستندات',
            'parent_id' => null,
        ]);
    }

    public function test_user_can_edit_a_folders_academic_metadata_fields(): void
    {
        $user = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $user->id]);

        $this->assertSame('files', $folder->fresh()->folder_type);

        $this->actingAs($user)->put(route('folders.update', $folder), [
            'folder_type' => 'thesis',
            'researcher_name' => 'أحمد محمد',
            'supervisor_name' => 'د. خالد العلي',
            'thesis_title' => 'أثر التغير المناخي',
            'publisher' => 'دار المعارف',
            'digital_library' => 'مكتبة الملك فهد الرقمية',
            'publication_year' => '2024',
        ])->assertRedirect();

        $this->assertDatabaseHas('folders', [
            'id' => $folder->id,
            'folder_type' => 'thesis',
            'researcher_name' => 'أحمد محمد',
            'supervisor_name' => 'د. خالد العلي',
            'thesis_title' => 'أثر التغير المناخي',
            'publisher' => 'دار المعارف',
            'digital_library' => 'مكتبة الملك فهد الرقمية',
            'publication_year' => '2024',
        ]);
    }

    public function test_folder_type_is_rejected_when_not_files_or_thesis(): void
    {
        $user = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->put(route('folders.update', $folder), [
            'folder_type' => 'something-else',
        ])->assertSessionHasErrors('folder_type');
    }

    public function test_user_cannot_edit_another_users_folder_metadata(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder)->put(route('folders.update', $folder), [
            'researcher_name' => 'محاولة تعديل',
        ])->assertForbidden();
    }

    public function test_user_can_view_a_documents_full_extracted_text(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create([
            'user_id' => $user->id,
            'content_text' => 'نص كامل مستخرج من المستند للاختبار.',
            'content_indexed_at' => now(),
        ]);

        $this->actingAs($user)->getJson(route('files.content', $file))
            ->assertOk()
            ->assertJson(['content' => 'نص كامل مستخرج من المستند للاختبار.']);
    }

    public function test_user_cannot_view_another_users_document_content(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $file = File::factory()->create(['user_id' => $owner->id, 'content_text' => 'سري']);

        $this->actingAs($intruder)->getJson(route('files.content', $file))->assertForbidden();
    }

    public function test_folder_store_returns_json_with_the_new_folder_when_the_client_wants_json(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('folders.store'), ['name' => 'Photos']);

        $response->assertOk();
        $folderId = $response->json('folder.id');
        $this->assertIsInt($folderId);
        $this->assertDatabaseHas('folders', ['id' => $folderId, 'user_id' => $user->id, 'name' => 'Photos', 'parent_id' => null]);
    }

    public function test_user_can_upload_a_picked_folder_tree_by_recreating_its_structure(): void
    {
        // Mirrors the client-side folder-upload orchestrator: for a picked
        // directory "Photos/2024/beach.jpg", the frontend creates "Photos"
        // then "2024" under it (caching each path -> id) before uploading
        // the file into the resolved leaf folder — exercised here end to
        // end against the real endpoints instead of just unit-testing JS.
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();

        $photos = $this->actingAs($user)->postJson(route('folders.store'), ['name' => 'Photos'])
            ->assertOk()->json('folder.id');

        $year = $this->actingAs($user)->postJson(route('folders.store'), [
            'name' => '2024',
            'parent_id' => $photos,
        ])->assertOk()->json('folder.id');

        $content = str_repeat('b', 512);
        $init = $this->actingAs($user)->postJson(route('uploads.init'), [
            'name' => 'beach.jpg',
            'size' => strlen($content),
            'folder_id' => $year,
        ])->assertOk()->json();

        $this->actingAs($user)->post(route('uploads.chunk'), [
            'identifier' => $init['identifier'],
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk0', $content),
        ])->assertOk();

        $finalize = $this->actingAs($user)->postJson(route('uploads.finalize'), [
            'identifier' => $init['identifier'],
        ])->assertOk()->json();

        $this->assertDatabaseHas('folders', ['id' => $year, 'name' => '2024', 'parent_id' => $photos]);
        $this->assertDatabaseHas('files', [
            'id' => $finalize['file']['id'],
            'name' => 'beach.jpg',
            'folder_id' => $year,
        ]);
    }

    public function test_user_can_upload_a_file_in_a_single_chunk_and_see_it_on_the_dashboard(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();

        $content = str_repeat('a', 1024);

        $init = $this->actingAs($user)->postJson(route('uploads.init'), [
            'name' => 'note.txt',
            'size' => strlen($content),
        ])->assertOk()->json();

        $this->assertSame(1, $init['total_chunks']);

        $this->actingAs($user)->post(route('uploads.chunk'), [
            'identifier' => $init['identifier'],
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk0', $content),
        ])->assertOk();

        $finalize = $this->actingAs($user)->postJson(route('uploads.finalize'), [
            'identifier' => $init['identifier'],
        ])->assertOk()->json();

        $this->assertSame('note.txt', $finalize['file']['name']);
        $this->assertDatabaseHas('files', ['user_id' => $user->id, 'name' => 'note.txt']);

        $user->refresh();
        $this->assertSame(strlen($content), $user->storage_used_bytes);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->component('FileManager/Index')
                ->has('files', 1)
            );
    }

    public function test_user_cannot_access_another_users_file(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $file = File::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder)
            ->delete(route('files.destroy', $file))
            ->assertForbidden();
    }

    public function test_deleting_a_file_moves_it_to_trash_and_it_can_be_restored(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'size' => 100]);
        $user->update(['storage_used_bytes' => 100]);

        $this->actingAs($user)->delete(route('files.destroy', $file))->assertRedirect();
        $this->assertSoftDeleted('files', ['id' => $file->id]);

        $this->actingAs($user)->post(route('trash.restore'), [
            'type' => 'file',
            'id' => $file->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('files', ['id' => $file->id, 'deleted_at' => null]);
    }

    public function test_a_regular_user_cannot_access_the_admin_panel(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_an_admin_can_access_the_admin_panel(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_user_can_create_a_share_link_for_a_file(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('shares.store'), [
            'shareable_type' => 'file',
            'shareable_id' => $file->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('shares', ['shareable_type' => 'file', 'shareable_id' => $file->id]);
    }

    public function test_public_visitor_can_download_a_shared_file(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'path' => 'shared.txt']);
        Storage::disk(config('filenest.disk'))->put('shared.txt', 'hello world');

        $share = \App\Models\Share::factory()->create([
            'user_id' => $user->id,
            'shareable_type' => 'file',
            'shareable_id' => $file->id,
        ]);

        $this->get(route('share.public.show', $share->token))->assertOk();
        $this->get(route('share.public.download', $share->token))->assertOk();
    }
}
