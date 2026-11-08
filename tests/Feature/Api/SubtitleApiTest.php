<?php

namespace Tests\Feature\Api;

use App\Models\File;
use App\Models\Subtitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubtitleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_upload_a_srt_subtitle_via_the_api(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4', 'extension' => 'mp4']);

        $srt = UploadedFile::fake()->createWithContent(
            'subs.srt',
            "1\n00:00:01,000 --> 00:00:02,000\nHi\n"
        );

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/files/{$file->id}/subtitles", [
            'subtitle' => $srt,
            'language' => 'ar',
            'label' => 'العربية',
        ]);

        $response->assertCreated()->assertJsonPath('subtitle.label', 'العربية');
        $this->assertDatabaseHas('subtitles', ['file_id' => $file->id, 'language' => 'ar']);
    }

    public function test_a_user_cannot_upload_a_subtitle_to_another_users_file_via_the_api(): void
    {
        Storage::fake(config('filenest.disk'));
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $file = File::factory()->create(['user_id' => $owner->id]);

        $srt = UploadedFile::fake()->createWithContent('subs.srt', "1\n00:00:01,000 --> 00:00:02,000\nHi\n");

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/files/{$file->id}/subtitles", ['subtitle' => $srt, 'language' => 'ar', 'label' => 'AR'])
            ->assertForbidden();
    }

    public function test_subtitles_can_be_listed_via_the_api(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        Subtitle::create(['file_id' => $file->id, 'language' => 'en', 'label' => 'English', 'path' => 'x.vtt']);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/files/{$file->id}/subtitles")
            ->assertOk()
            ->assertJsonCount(1, 'subtitles')
            ->assertJsonPath('subtitles.0.label', 'English');
    }

    public function test_a_subtitles_vtt_content_can_be_fetched_via_the_api(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        Storage::disk(config('filenest.disk'))->put('sub.vtt', "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi\n");
        $subtitle = Subtitle::create(['file_id' => $file->id, 'language' => 'en', 'label' => 'English', 'path' => 'sub.vtt']);

        $response = $this->actingAs($user, 'sanctum')->get("/api/v1/subtitles/{$subtitle->id}");

        $response->assertOk();
        $this->assertStringContainsString('text/vtt', $response->headers->get('Content-Type'));
        $response->assertSee('Hi');
    }

    public function test_a_user_can_delete_their_own_subtitle_via_the_api(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        Storage::disk(config('filenest.disk'))->put('sub.vtt', 'WEBVTT');
        $subtitle = Subtitle::create(['file_id' => $file->id, 'language' => 'en', 'label' => 'English', 'path' => 'sub.vtt']);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/subtitles/{$subtitle->id}")
            ->assertOk();

        $this->assertDatabaseMissing('subtitles', ['id' => $subtitle->id]);
    }
}
