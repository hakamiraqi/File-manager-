<?php

namespace Tests\Feature;

use App\Jobs\TranscodeVideoQualities;
use App\Models\File;
use App\Models\Setting;
use App\Models\Subtitle;
use App\Models\User;
use App\Models\VideoQuality;
use App\Services\VideoTranscoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_heights_are_parsed_sorted_and_deduplicated(): void
    {
        Setting::set('video_quality_targets', '720, 360,480,360');

        $heights = (new VideoTranscoder)->targetHeights();

        $this->assertSame([360, 480, 720], $heights);
    }

    public function test_transcoding_is_skipped_entirely_when_disabled(): void
    {
        Setting::set('video_transcoding_enabled', false);
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        Storage::disk(config('filenest.disk'))->put($file->path, 'fake-video-bytes');

        (new TranscodeVideoQualities($file->id))->handle(app(VideoTranscoder::class));

        $this->assertDatabaseCount('video_qualities', 0);
    }

    public function test_transcoding_never_throws_and_only_produces_variants_when_ffmpeg_is_actually_available(): void
    {
        // Environment-agnostic like ThumbnailTest: this sandbox may or may
        // not have ffmpeg installed. Either way the job must complete
        // without error, and must only have produced rows if the tool
        // that makes that possible is actually present.
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        Storage::disk(config('filenest.disk'))->put($file->path, 'not-a-real-video');

        $transcoder = app(VideoTranscoder::class);
        (new TranscodeVideoQualities($file->id))->handle($transcoder);

        if (! $transcoder->ffmpegAvailable()) {
            $this->assertDatabaseCount('video_qualities', 0);
        }
    }

    public function test_a_job_for_a_missing_or_non_video_file_is_a_harmless_noop(): void
    {
        (new TranscodeVideoQualities(999999))->handle(app(VideoTranscoder::class));

        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'text/plain', 'extension' => 'txt']);
        (new TranscodeVideoQualities($file->id))->handle(app(VideoTranscoder::class));

        $this->assertDatabaseCount('video_qualities', 0);
    }

    public function test_video_qualities_are_only_appended_when_the_relation_is_eager_loaded(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        VideoQuality::create(['file_id' => $file->id, 'height' => 480, 'path' => 'x.mp4', 'size' => 100]);

        $lazy = File::find($file->id);
        $this->assertSame([], $lazy->video_qualities);

        $eager = File::with('qualities')->find($file->id);
        $this->assertCount(1, $eager->video_qualities);
        $this->assertSame(480, $eager->video_qualities[0]['height']);
    }

    public function test_a_specific_quality_variant_can_be_streamed_by_its_owner(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        Storage::disk(config('filenest.disk'))->put('variant-480.mp4', 'variant-bytes');
        VideoQuality::create(['file_id' => $file->id, 'height' => 480, 'path' => 'variant-480.mp4', 'size' => 13]);

        $this->actingAs($user)
            ->get(route('files.preview-quality', [$file->id, 480]))
            ->assertOk();
    }

    public function test_requesting_an_unavailable_quality_returns_404(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('files.preview-quality', [$file->id, 720]))
            ->assertNotFound();
    }

    public function test_a_user_cannot_fetch_another_users_quality_variant(): void
    {
        Storage::fake(config('filenest.disk'));
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $file = File::factory()->create(['user_id' => $owner->id]);
        Storage::disk(config('filenest.disk'))->put('v.mp4', 'bytes');
        VideoQuality::create(['file_id' => $file->id, 'height' => 480, 'path' => 'v.mp4', 'size' => 5]);

        $this->actingAs($intruder)
            ->get(route('files.preview-quality', [$file->id, 480]))
            ->assertForbidden();
    }

    public function test_permanently_deleting_a_file_also_removes_its_subtitle_and_quality_bytes_from_disk(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        $disk = $file->disk();
        $disk->put($file->path, 'original');
        $disk->put('sub.vtt', 'WEBVTT');
        $disk->put('q.mp4', 'variant');
        Subtitle::create(['file_id' => $file->id, 'language' => 'ar', 'label' => 'AR', 'path' => 'sub.vtt']);
        VideoQuality::create(['file_id' => $file->id, 'height' => 480, 'path' => 'q.mp4', 'size' => 7]);

        $file->delete();
        $file->forceDelete();

        $this->assertFalse($disk->exists('sub.vtt'));
        $this->assertFalse($disk->exists('q.mp4'));
    }
}
