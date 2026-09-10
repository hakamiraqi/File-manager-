<?php

namespace Tests\Feature\Api;

use App\Models\File;
use App\Models\User;
use App\Models\VideoQuality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoQualityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_specific_quality_variant_can_be_streamed_via_the_api(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        Storage::disk(config('filenest.disk'))->put('variant-480.mp4', 'variant-bytes');
        VideoQuality::create(['file_id' => $file->id, 'height' => 480, 'path' => 'variant-480.mp4', 'size' => 13]);

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/files/{$file->id}/preview/480")
            ->assertOk();
    }

    public function test_requesting_an_unavailable_quality_returns_404_via_the_api(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->get("/api/v1/files/{$file->id}/preview/720")
            ->assertNotFound();
    }

    public function test_a_user_cannot_fetch_another_users_quality_variant_via_the_api(): void
    {
        Storage::fake(config('filenest.disk'));
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $file = File::factory()->create(['user_id' => $owner->id]);
        Storage::disk(config('filenest.disk'))->put('v.mp4', 'bytes');
        VideoQuality::create(['file_id' => $file->id, 'height' => 480, 'path' => 'v.mp4', 'size' => 5]);

        $this->actingAs($intruder, 'sanctum')
            ->get("/api/v1/files/{$file->id}/preview/480")
            ->assertForbidden();
    }

    public function test_the_browse_endpoint_exposes_video_qualities_and_subtitles_url(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        VideoQuality::create(['file_id' => $file->id, 'height' => 720, 'path' => 'a.mp4', 'size' => 1]);
        VideoQuality::create(['file_id' => $file->id, 'height' => 480, 'path' => 'b.mp4', 'size' => 1]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/browse')->assertOk();

        $data = $response->json('files.0');
        $this->assertCount(2, $data['video_qualities']);
        $this->assertSame(720, $data['video_qualities'][0]['height']);
        $this->assertStringContainsString("/api/v1/files/{$file->id}/preview/720", $data['video_qualities'][0]['src']);
        $this->assertStringContainsString("/api/v1/files/{$file->id}/subtitles", $data['subtitles_url']);
    }

    public function test_a_non_video_file_has_no_subtitles_url_and_empty_qualities(): void
    {
        $user = User::factory()->create();
        File::factory()->create(['user_id' => $user->id, 'mime_type' => 'text/plain', 'extension' => 'txt']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/browse')->assertOk();

        $data = $response->json('files.0');
        $this->assertSame([], $data['video_qualities']);
        $this->assertNull($data['subtitles_url']);
    }
}
