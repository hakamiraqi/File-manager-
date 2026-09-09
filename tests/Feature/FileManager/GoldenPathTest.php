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
