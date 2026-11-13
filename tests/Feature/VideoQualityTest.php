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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
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

    public function test_only_qualities_smaller_than_the_original_are_made_and_its_height_is_kept(): void
    {
        config(['services.video.ffmpeg' => base_path('artisan')]); // any existing file stands in for ffmpeg
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        Storage::disk(config('filenest.disk'))->put($file->path, 'video');
        Process::fake(fn ($p) => is_array($p->command) && count($p->command) === 4
            ? Process::result('', "Input #0, mov\n  Stream #0:0[0x1](und): Video: h264 (High), yuv420p(tv), 854x480 [SAR 1:1], 25 fps\n", 1)
            : Process::result(''));

        app(VideoTranscoder::class)->transcode($file);

        Process::assertRan(fn ($p) => is_array($p->command) && in_array("scale=-2:'min(ih,360)'", $p->command, true));
        Process::assertNotRan(fn ($p) => is_array($p->command) && in_array("scale=-2:'min(ih,480)'", $p->command, true));
        Process::assertNotRan(fn ($p) => is_array($p->command) && in_array("scale=-2:'min(ih,720)'", $p->command, true));
        $this->assertSame(480, $file->fresh()->video_height);
        $this->assertNotNull($file->fresh()->video_checked_at);
    }

    public function test_upright_phone_videos_count_their_real_height(): void
    {
        config(['services.video.ffmpeg' => base_path('artisan')]);
        Process::fake(fn () => Process::result('', "  Stream #0:0: Video: hevc, yuv420p, 1920x1080, 30 fps\n    Side data:\n      displaymatrix: rotation of -90.00 degrees\n", 1));

        $this->assertSame(1920, \App\Support\Ffmpeg::height('/x.mov'));
    }

    public function test_videos_without_qualities_are_queued_a_few_at_a_time(): void
    {
        config(['services.video.ffmpeg' => base_path('artisan')]);
        Queue::fake();
        $user = User::factory()->create();
        $videos = File::factory()->count(3)->create(['user_id' => $user->id, 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        File::factory()->create(['user_id' => $user->id, 'mime_type' => 'application/pdf', 'extension' => 'pdf']);

        $this->artisan('videos:qualities', ['--limit' => 2])->assertSuccessful();
        Queue::assertPushed(TranscodeVideoQualities::class, 2);
        $this->assertSame(1, File::where('mime_type', 'video/mp4')->whereNull('video_checked_at')->count());

        $this->artisan('videos:qualities', ['--limit' => 2])->assertSuccessful();
        Queue::assertPushed(TranscodeVideoQualities::class, 3);
        $this->artisan('videos:qualities')->assertSuccessful();
        Queue::assertPushed(TranscodeVideoQualities::class, 3);
    }

    public function test_the_app_gets_the_original_height(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'video_height' => 1080]);
        VideoQuality::create(['file_id' => $file->id, 'height' => 480, 'path' => 'x.mp4', 'size' => 100]);

        $json = (new \App\Http\Resources\FileResource($file->load('qualities')))->toArray(request());
        $this->assertSame(1080, $json['video_height']);
        $this->assertSame(480, $json['video_qualities'][0]['height']);
    }
}
