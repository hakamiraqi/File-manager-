<?php

namespace Tests\Feature\Covers;

use App\Models\File;
use App\Models\Folder;
use App\Models\Setting;
use App\Models\User;
use App\Services\Covers\CoverFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BookCoversTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filenest.disk'));
        $this->admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        Setting::set('covers_min_pages', '0');
    }

    private function jpeg(int $w = 300, int $h = 450): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    private function book(string $name, ?string $text = null): File
    {
        $folder = Folder::factory()->create(['user_id' => $this->admin->id]);
        $f = File::factory()->create([
            'user_id' => $this->admin->id, 'folder_id' => $folder->id, 'name' => $name, 'extension' => 'pdf',
            'mime_type' => 'application/pdf', 'path' => $this->admin->id.'/'.uniqid().'.pdf', 'size' => 10,
            'content_text' => $text, 'content_indexed_at' => now(), 'thumbnail_path' => $this->admin->id.'/thumbnails/own.jpg',
        ]);
        $f->disk()->put($f->path, '%PDF');
        $f->disk()->put($f->thumbnail_path, 'own');

        return $f->fresh();
    }

    public function test_isbns_and_titles_are_read_carefully(): void
    {
        $this->assertSame(['9780262033848'], CoverFinder::isbns("Copyright 2009\nISBN 978-0-262-03384-8 (hardcover)\nphone 123456789"));
        $this->assertSame(['9780262033848'], CoverFinder::isbns('ردمك: ٩٧٨-٠-٢٦٢-٠٣٣٨٤-٨'));
        $this->assertSame([], CoverFinder::isbns('ISBN 978-0-262-03384-9 and 1234567890'));
        $this->assertNull(CoverFinder::titleFromName('IMG_2423 (صور).pdf'));
        $this->assertNull(CoverFinder::titleFromName('scan 3.pdf'));
        $this->assertSame('Introduction to Algorithms', CoverFinder::titleFromName('Introduction_to_Algorithms (3rd ed).pdf'));
        $this->assertGreaterThanOrEqual(0.85, CoverFinder::similarity('Introduction to Algorithms', 'Introduction to Algorithms: Third Edition'));
        $this->assertLessThan(0.85, CoverFinder::similarity('Introduction to Algorithms', 'Algorithms in C'));
    }

    public function test_the_nightly_run_finds_covers_by_isbn_and_by_title_and_keeps_the_own_picture(): void
    {
        Http::fake([
            'openlibrary.org/isbn/9780262033848.json' => Http::response(['title' => 'Introduction to Algorithms', 'covers' => [123]]),
            'openlibrary.org/search.json*' => Http::response(['docs' => [
                ['title' => 'Gray\'s Anatomy', 'author_name' => ['Henry Gray'], 'cover_i' => 456],
                ['title' => 'Something Else', 'cover_i' => 789],
            ]]),
            'covers.openlibrary.org/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $byIsbn = $this->book('clrs.pdf', 'ISBN 978-0-262-03384-8');
        $byTitle = $this->book('Grays_Anatomy.pdf');
        $lecture = $this->book('IMG_2423 (صور).pdf');

        // Outside the hours nothing happens; inside it runs.
        Carbon::setTestNow(Carbon::parse('2026-11-12 12:00', 'Asia/Baghdad'));
        $this->artisan('covers:run')->assertSuccessful();
        $this->assertNull($byIsbn->fresh()->cover_status);
        Carbon::setTestNow(Carbon::parse('2026-11-12 21:00', 'Asia/Baghdad'));
        $this->artisan('covers:run')->assertSuccessful();

        $a = $byIsbn->fresh();
        $this->assertSame('found', $a->cover_status);
        $this->assertSame('9780262033848', $a->cover_isbn);
        $this->assertSame($this->admin->id.'/thumbnails/own.jpg', $a->auto_thumbnail_path);
        $this->assertTrue($a->disk()->exists($a->thumbnail_path));
        $this->assertSame('found', $byTitle->fresh()->cover_status);
        $this->assertSame('none', $lecture->fresh()->cover_status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'covers.openlibrary.org/b/id/123-L.jpg'));

        // Taking the cover off brings the file's own picture back, and the run leaves it alone.
        $this->actingAs($this->admin)->deleteJson(route('files.cover.destroy', $a))->assertOk();
        $a->refresh();
        $this->assertSame($this->admin->id.'/thumbnails/own.jpg', $a->thumbnail_path);
        $this->assertSame('removed', $a->cover_status);
        Carbon::setTestNow();
    }

    public function test_without_internet_the_run_stops_and_marks_nothing(): void
    {
        Http::fake(fn () => throw new ConnectionException('no network'));
        $f = $this->book('clrs.pdf', 'ISBN 978-0-262-03384-8');
        $this->artisan('covers:run', ['--force' => true])->assertSuccessful();
        $this->assertNull($f->fresh()->cover_status);
        $this->assertStringContainsString('إنترنت', (string) json_decode((string) Setting::get('covers_last_run'), true)['error']);
    }

    public function test_choosing_a_cover_by_hand_and_the_admin_page(): void
    {
        Http::fake([
            'openlibrary.org/search.json*' => Http::response(['docs' => [['title' => 'Netter Atlas', 'cover_i' => 9]]]),
            'covers.openlibrary.org/*' => Http::response($this->jpeg(), 200),
        ]);
        $f = $this->book('netter atlas.pdf');
        $this->actingAs($this->admin);
        $this->getJson(route('files.cover.search', $f))->assertOk()->assertJsonPath('results.0.url', 'https://covers.openlibrary.org/b/id/9-L.jpg');
        $this->postJson(route('files.cover.store', $f), ['url' => 'https://evil.example.com/x.jpg'])->assertStatus(422);
        $this->postJson(route('files.cover.store', $f), ['url' => 'https://covers.openlibrary.org/b/id/9-L.jpg', 'source' => 'openlibrary'])->assertOk();
        $this->assertSame('manual', $f->fresh()->cover_status);

        $upload = UploadedFile::fake()->createWithContent('c.jpg', $this->jpeg(200, 300));
        $this->post(route('files.cover.store', $f), ['image' => $upload], ['Accept' => 'application/json'])->assertOk();
        $f->refresh();
        $this->assertSame('upload', $f->cover_source);
        // Still the first own picture, not the earlier cover.
        $this->assertSame($this->admin->id.'/thumbnails/own.jpg', $f->auto_thumbnail_path);

        $this->get(route('admin.covers.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Covers')->where('counts.manual', 1)->where('files.data.0.id', $f->id));
        $this->post(route('admin.covers.update'), [
            'covers_enabled' => true, 'covers_days' => [0, 1, 2], 'covers_start' => '20:00', 'covers_end' => '06:00', 'covers_batch' => 40,
            'covers_min_pages' => 30, 'covers_retry_days' => 30, 'covers_kinds' => ['pdf'], 'covers_openlibrary' => true, 'covers_google' => true,
            'covers_google_key' => 'AIza-test', 'covers_title_search' => true,
        ])->assertRedirect();
        $this->assertNotSame('AIza-test', Setting::get('covers_google_key'));
        $this->assertSame('AIza-test', \App\Services\Covers\CoverSettings::googleKey());

        // Someone who cannot change the file cannot change its cover.
        $this->actingAs(User::factory()->create())->postJson(route('files.cover.store', $f), ['page' => 1])->assertForbidden();
    }
}
