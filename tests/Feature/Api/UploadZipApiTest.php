<?php

namespace Tests\Feature\Api;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class UploadZipApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_small_file_can_be_uploaded_end_to_end_through_the_chunked_api(): void
    {
        Storage::fake(config('filenest.disk'));
        Storage::fake('local');
        $user = User::factory()->create();
        $content = 'hello from the api';

        $init = $this->actingAs($user, 'sanctum')->postJson('/api/v1/uploads/init', [
            'name' => 'note.txt',
            'size' => strlen($content),
        ])->assertOk();

        $identifier = $init->json('identifier');

        $this->actingAs($user, 'sanctum')->post('/api/v1/uploads/chunk', [
            'identifier' => $identifier,
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk0', $content),
        ])->assertOk();

        $finalize = $this->actingAs($user, 'sanctum')->postJson('/api/v1/uploads/finalize', [
            'identifier' => $identifier,
        ])->assertOk();

        $finalize->assertJsonPath('file.name', 'note.txt');
        $this->assertDatabaseHas('files', ['user_id' => $user->id, 'name' => 'note.txt']);
    }

    public function test_bulk_zip_download_works_through_the_api_with_a_bearer_token(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $disk = Storage::disk(config('filenest.disk'));

        $fileA = File::factory()->create(['user_id' => $user->id, 'name' => 'a.txt', 'path' => 'a.txt']);
        $fileB = File::factory()->create(['user_id' => $user->id, 'name' => 'b.txt', 'path' => 'b.txt']);
        $disk->put('a.txt', 'content A');
        $disk->put('b.txt', 'content B');

        $token = $user->createToken('device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/download-zip?'.http_build_query(['file_ids' => [$fileA->id, $fileB->id]]));

        $response->assertOk();

        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($zipPath, $response->streamedContent());

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $this->assertSame('content A', $zip->getFromName('a.txt'));
        $zip->close();
        unlink($zipPath);
    }
}
