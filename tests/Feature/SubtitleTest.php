<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Subtitle;
use App\Models\User;
use App\Services\SrtToVttConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SubtitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_srt_to_vtt_converter_translates_timestamps_and_header(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:04,500\nHello there.\n\n2\n00:00:05,200 --> 00:00:07,000\nSecond line.\n";

        $vtt = (new SrtToVttConverter)->convert($srt);

        $this->assertStringStartsWith("WEBVTT\n\n", $vtt);
        $this->assertStringContainsString('00:00:01.000 --> 00:00:04.500', $vtt);
        $this->assertStringContainsString('00:00:05.200 --> 00:00:07.000', $vtt);
        $this->assertStringNotContainsString(',', $vtt);
        $this->assertStringContainsString('Hello there.', $vtt);
    }

    public function test_a_user_can_upload_a_srt_subtitle_for_their_own_video(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4', 'extension' => 'mp4']);

        $srt = UploadedFile::fake()->createWithContent(
            'subs.srt',
            "1\n00:00:01,000 --> 00:00:02,000\nHi\n"
        );

        $response = $this->actingAs($user)->post(route('subtitles.store', $file), [
            'subtitle' => $srt,
            'language' => 'ar',
            'label' => 'العربية',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('subtitles', [
            'file_id' => $file->id,
            'language' => 'ar',
            'label' => 'العربية',
        ]);

        $subtitle = Subtitle::first();
        $this->assertTrue(Storage::disk(config('filenest.disk'))->exists($subtitle->path));
        $this->assertStringStartsWith('WEBVTT', Storage::disk(config('filenest.disk'))->get($subtitle->path));
    }

    public function test_a_user_cannot_upload_a_subtitle_to_another_users_file(): void
    {
        Storage::fake(config('filenest.disk'));
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $file = File::factory()->create(['user_id' => $owner->id]);

        $srt = UploadedFile::fake()->createWithContent('subs.srt', "1\n00:00:01,000 --> 00:00:02,000\nHi\n");

        $this->actingAs($intruder)
            ->post(route('subtitles.store', $file), ['subtitle' => $srt, 'language' => 'ar', 'label' => 'العربية'])
            ->assertForbidden();
    }

    public function test_subtitles_can_be_listed_for_a_file(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        Subtitle::create(['file_id' => $file->id, 'language' => 'en', 'label' => 'English', 'path' => 'x.vtt']);

        $this->actingAs($user)->getJson(route('subtitles.index', $file))
            ->assertOk()
            ->assertJsonCount(1, 'subtitles')
            ->assertJsonPath('subtitles.0.label', 'English');
    }

    public function test_a_subtitles_vtt_content_can_be_fetched(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        Storage::disk(config('filenest.disk'))->put('sub.vtt', "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHi\n");
        $subtitle = Subtitle::create(['file_id' => $file->id, 'language' => 'en', 'label' => 'English', 'path' => 'sub.vtt']);

        $response = $this->actingAs($user)->get(route('subtitles.show', $subtitle));

        $response->assertOk();
        $this->assertStringContainsString('text/vtt', $response->headers->get('Content-Type'));
        $response->assertSee('Hi');
    }

    public function test_a_user_can_delete_their_own_subtitle(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        Storage::disk(config('filenest.disk'))->put('sub.vtt', 'WEBVTT');
        $subtitle = Subtitle::create(['file_id' => $file->id, 'language' => 'en', 'label' => 'English', 'path' => 'sub.vtt']);

        $this->actingAs($user)->delete(route('subtitles.destroy', $subtitle))->assertRedirect();

        $this->assertDatabaseMissing('subtitles', ['id' => $subtitle->id]);
        $this->assertFalse(Storage::disk(config('filenest.disk'))->exists('sub.vtt'));
    }
}
