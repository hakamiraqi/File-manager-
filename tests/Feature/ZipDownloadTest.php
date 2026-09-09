<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ZipDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_downloading_multiple_selected_files_produces_a_zip_with_all_of_them(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $disk = Storage::disk(config('filenest.disk'));

        $fileA = File::factory()->create(['user_id' => $user->id, 'name' => 'a.txt', 'path' => 'a.txt']);
        $fileB = File::factory()->create(['user_id' => $user->id, 'name' => 'b.txt', 'path' => 'b.txt']);
        $disk->put('a.txt', 'content A');
        $disk->put('b.txt', 'content B');

        // The frontend triggers this via a plain `window.location.href`
        // navigation (so the browser's download UI kicks in), which is
        // always a GET — the route and this test must match that.
        $response = $this->actingAs($user)->get(route('files.download-zip', [
            'file_ids' => [$fileA->id, $fileB->id],
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');

        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $this->assertSame('content A', $zip->getFromName('a.txt'));
        $this->assertSame('content B', $zip->getFromName('b.txt'));
        $zip->close();
        unlink($zipPath);
    }

    public function test_downloading_a_selected_folder_includes_its_contents_recursively(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $disk = Storage::disk(config('filenest.disk'));

        $folder = Folder::factory()->create(['user_id' => $user->id, 'name' => 'reports']);
        $sub = Folder::factory()->create(['user_id' => $user->id, 'parent_id' => $folder->id, 'name' => 'q1']);
        File::factory()->create(['user_id' => $user->id, 'folder_id' => $sub->id, 'name' => 'nested.txt', 'path' => 'nested.txt']);
        $disk->put('nested.txt', 'deep content');

        $response = $this->actingAs($user)->get(route('files.download-zip', [
            'folder_ids' => [$folder->id],
        ]));

        $response->assertOk();

        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $this->assertSame('deep content', $zip->getFromName('reports/q1/nested.txt'));
        $zip->close();
        unlink($zipPath);
    }

    public function test_a_user_cannot_include_another_users_file_in_their_zip(): void
    {
        Storage::fake(config('filenest.disk'));
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $file = File::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder)
            ->get(route('files.download-zip', ['file_ids' => [$file->id]]))
            ->assertNotFound();
    }

    public function test_bulk_download_is_blocked_when_the_user_lacks_download_permission(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'path' => 'x.txt']);
        Storage::disk(config('filenest.disk'))->put('x.txt', 'x');

        \App\Models\RolePermission::where('role', 'user')->where('action', 'download')->update(['allowed' => false]);

        $this->actingAs($user)
            ->get(route('files.download-zip', ['file_ids' => [$file->id]]))
            ->assertForbidden();
    }

    public function test_bulk_download_is_rejected_when_it_exceeds_the_configured_max_zip_files(): void
    {
        Setting::set('max_zip_files', 1);
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $disk = Storage::disk(config('filenest.disk'));

        $fileA = File::factory()->create(['user_id' => $user->id, 'path' => 'a.txt']);
        $fileB = File::factory()->create(['user_id' => $user->id, 'path' => 'b.txt']);
        $disk->put('a.txt', 'a');
        $disk->put('b.txt', 'b');

        $this->actingAs($user)
            ->get(route('files.download-zip', ['file_ids' => [$fileA->id, $fileB->id]]))
            ->assertStatus(422);
    }
}
