<?php

namespace Tests\Feature;

use App\Jobs\GenerateThumbnail;
use App\Models\File;
use App\Models\Folder;
use App\Models\Setting;
use App\Models\User;
use App\Services\ThumbnailGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ThumbnailTest extends TestCase
{
    use RefreshDatabase;

    private function makeJpegFixture(int $width = 800, int $height = 600): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'jpg').'.jpg';
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return $path;
    }

    public function test_uploading_an_image_generates_a_real_resized_thumbnail(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'extension' => 'jpg', 'mime_type' => 'image/jpeg']);

        $source = $this->makeJpegFixture(1200, 900);
        Storage::disk(config('filenest.disk'))->put($file->path, file_get_contents($source));

        (new GenerateThumbnail($file->id))->handle(app(ThumbnailGenerator::class));

        $file->refresh();
        $this->assertNotNull($file->thumbnail_path);
        $this->assertTrue($file->disk()->exists($file->thumbnail_path));

        $thumbInfo = getimagesizefromstring($file->disk()->get($file->thumbnail_path));
        $this->assertLessThanOrEqual(400, $thumbInfo[0]);
        $this->assertLessThanOrEqual(400, $thumbInfo[1]);
        $this->assertSame(IMAGETYPE_JPEG, $thumbInfo[2]);
    }

    public function test_thumbnail_respects_the_configured_max_dimension(): void
    {
        Setting::set('thumbnail_max_px', 120);
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'extension' => 'png', 'mime_type' => 'image/png']);

        $image = imagecreatetruecolor(500, 500);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
        $path = tempnam(sys_get_temp_dir(), 'png').'.png';
        imagepng($image, $path);
        imagedestroy($image);
        Storage::disk(config('filenest.disk'))->put($file->path, file_get_contents($path));

        (new GenerateThumbnail($file->id))->handle(app(ThumbnailGenerator::class));

        $file->refresh();
        $thumbInfo = getimagesizefromstring($file->disk()->get($file->thumbnail_path));
        $this->assertLessThanOrEqual(120, $thumbInfo[0]);
        $this->assertLessThanOrEqual(120, $thumbInfo[1]);
    }

    public function test_thumbnail_generation_is_skipped_when_disabled(): void
    {
        Setting::set('thumbnails_enabled', false);
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'extension' => 'jpg', 'mime_type' => 'image/jpeg']);
        Storage::disk(config('filenest.disk'))->put($file->path, file_get_contents($this->makeJpegFixture()));

        (new GenerateThumbnail($file->id))->handle(app(ThumbnailGenerator::class));

        $this->assertNull($file->fresh()->thumbnail_path);
    }

    public function test_a_non_image_non_video_non_pdf_extension_never_gets_a_thumbnail(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'extension' => 'zip', 'mime_type' => 'application/zip']);
        Storage::disk(config('filenest.disk'))->put($file->path, 'pk-zip-bytes');

        (new GenerateThumbnail($file->id))->handle(app(ThumbnailGenerator::class));

        $this->assertNull($file->fresh()->thumbnail_path);
    }

    public function test_a_user_cannot_fetch_another_users_thumbnail(): void
    {
        Storage::fake(config('filenest.disk'));
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $file = File::factory()->create(['user_id' => $owner->id]);
        $file->forceFill(['thumbnail_path' => $owner->id.'/thumbnails/x.jpg'])->save();
        Storage::disk(config('filenest.disk'))->put($file->thumbnail_path, 'jpeg-bytes');

        $this->actingAs($intruder)
            ->get(route('files.thumbnail', $file->id))
            ->assertForbidden();
    }

    public function test_thumbnail_route_returns_404_when_none_was_generated(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('files.thumbnail', $file->id))
            ->assertNotFound();
    }

    public function test_copying_a_file_duplicates_its_thumbnail_instead_of_regenerating_it(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $user->id]);
        $file = File::factory()->create(['user_id' => $user->id]);

        $disk = $file->disk();
        $disk->put($file->path, 'original-bytes');
        $file->forceFill(['thumbnail_path' => $user->id.'/thumbnails/orig.jpg'])->save();
        $disk->put($file->thumbnail_path, 'thumb-bytes');

        $this->actingAs($user)->post(route('files.copy', $file->id), ['folder_id' => $folder->id])->assertRedirect();

        $copy = File::where('id', '!=', $file->id)->where('folder_id', $folder->id)->first();
        $this->assertNotNull($copy->thumbnail_path);
        $this->assertNotSame($file->thumbnail_path, $copy->thumbnail_path);
        $this->assertSame('thumb-bytes', $disk->get($copy->thumbnail_path));
    }

    public function test_pdf_and_video_support_tracks_whether_the_optional_tool_is_installed(): void
    {
        // Environment-agnostic on purpose: whichever server this suite runs
        // on may or may not have ffmpeg/Imagick installed. What must always
        // hold is that "supports" never claims a format the installed tools
        // can't actually produce, and never throws either way.
        $generator = app(ThumbnailGenerator::class);

        $this->assertSame($generator->imagickAvailable(), $generator->supports('pdf'));
        $this->assertSame($generator->ffmpegAvailable(), $generator->supports('mp4'));
        $this->assertNull($generator->generate('/nonexistent/path.pdf', 'pdf'));
    }
}
