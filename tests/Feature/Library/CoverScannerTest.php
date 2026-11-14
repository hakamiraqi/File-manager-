<?php

namespace Tests\Feature\Library;

use App\Models\User;
use App\Services\Library\CoverText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CoverScannerTest extends TestCase
{
    use RefreshDatabase;

    private function tsv(): string
    {
        $row = fn (int $block, int $line, int $word, int $top, int $h, int $conf, string $text) => implode("\t", [5, 1, $block, 1, $line, $word, 10, $top, 50, $h, $conf, $text]);

        return implode("\n", [
            "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext",
            implode("\t", [1, 1, 0, 0, 0, 0, 0, 0, 600, 900, -1, '']),
            $row(1, 1, 1, 40, 18, 91, 'FOURTH'), $row(1, 1, 2, 40, 18, 90, 'EDITION'),
            $row(2, 1, 1, 200, 64, 95, 'Contemporary'), $row(2, 1, 2, 200, 60, 95, 'Oral'),
            $row(2, 2, 1, 280, 58, 93, 'Surgery'),
            $row(3, 1, 1, 420, 9, 20, '~~'), $row(3, 2, 1, 430, 9, 80, '|'),
            $row(4, 1, 1, 700, 24, 88, 'Peterson'), $row(4, 1, 2, 700, 24, 85, 'Ellis'),
            $row(5, 1, 1, 860, 12, 80, 'ISBN'), $row(5, 1, 2, 860, 12, 80, '978-0-323-41492-0'),
        ]);
    }

    public function test_cover_lines_keep_their_size_and_the_biggest_ones_are_the_title(): void
    {
        $lines = CoverText::lines($this->tsv());

        $this->assertSame(['FOURTH EDITION', 'Contemporary Oral', 'Surgery', 'Peterson Ellis', 'ISBN 978-0-323-41492-0'], array_column($lines, 'text'));
        $this->assertSame('Contemporary Oral Surgery', CoverText::title($lines));
        $this->assertNull(CoverText::title([]));
    }

    public function test_a_librarian_gets_the_text_of_a_photographed_cover_and_readers_do_not(): void
    {
        config(['services.ocr.tesseract' => base_path('artisan')]); // any existing file stands in for tesseract
        Cache::flush();
        Process::fake(fn ($p) => in_array('--list-langs', (array) $p->command, true)
            ? Process::result("List of available languages (2):\nara\neng\n")
            : Process::result($this->tsv()));
        $image = fn () => UploadedFile::fake()->image('cover.jpg', 300, 450);

        $this->actingAs(User::factory()->create())->post(route('library.manage.cover-text'), ['image' => $image()])->assertForbidden();

        $this->actingAs(User::factory()->create(['is_librarian' => true]))
            ->post(route('library.manage.cover-text'), ['image' => $image()])
            ->assertOk()
            ->assertJsonPath('title', 'Contemporary Oral Surgery')
            ->assertJsonPath('isbn', '9780323414920')
            ->assertJsonPath('lines.3.text', 'Peterson Ellis');
        Process::assertRan(fn ($p) => in_array('tsv', (array) $p->command, true) && in_array('ara+eng', (array) $p->command, true));
    }

    public function test_the_apps_reach_the_cover_text_the_file_cover_and_the_covers_page(): void
    {
        config(['services.ocr.tesseract' => base_path('artisan')]);
        Cache::flush();
        Process::fake(fn ($p) => in_array('--list-langs', (array) $p->command, true) ? Process::result("ara\neng\n") : Process::result($this->tsv()));
        \Illuminate\Support\Facades\Storage::fake(config('filenest.disk'));

        $staff = User::factory()->create(['is_librarian' => true]);
        $this->actingAs($staff, 'sanctum')->post('/api/v1/library/manage/cover-text', ['image' => UploadedFile::fake()->image('c.jpg', 300, 450)])
            ->assertOk()->assertJsonPath('title', 'Contemporary Oral Surgery');

        // A file's cover from an uploaded picture, then removed again.
        $owner = User::factory()->create();
        $file = \App\Models\File::factory()->create(['user_id' => $owner->id, 'name' => 'book.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf']);
        $this->actingAs($owner, 'sanctum')->post("/api/v1/files/{$file->id}/cover", ['image' => UploadedFile::fake()->image('c.jpg', 300, 450)])->assertOk();
        $this->assertSame('manual', $file->fresh()->cover_status);
        $this->actingAs(User::factory()->create(), 'sanctum')->deleteJson("/api/v1/files/{$file->id}/cover")->assertForbidden();
        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/files/{$file->id}/cover")->assertOk();
        $this->assertSame('removed', $file->fresh()->cover_status);

        // The admin page as JSON, and saving its settings.
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/covers')->assertOk()
            ->assertJsonStructure(['settings', 'googleKeySet', 'counts' => ['found', 'due'], 'books', 'state', 'files' => ['data']]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/covers', [
            'covers_enabled' => true, 'covers_start' => '21:00', 'covers_end' => '05:00', 'covers_batch' => 25,
            'covers_min_pages' => 30, 'covers_retry_days' => 30, 'covers_kinds' => ['pdf'], 'covers_days' => [0, 1],
        ])->assertOk()->assertJsonStructure(['message']);
        $this->assertSame('21:00', \App\Models\Setting::get('covers_start'));
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/admin/covers')->assertForbidden();
    }

    public function test_the_editor_logo_is_the_sites_own(): void
    {
        \App\Models\Setting::set('site_name', 'مكتبة الكلية');
        $this->get('/office-brand/logo')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('مكتبة الكلية', false);
    }
}
