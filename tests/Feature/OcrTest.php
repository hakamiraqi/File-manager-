<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\OcrLoadSample;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Ocr\LoadMonitor;
use App\Services\Ocr\OcrEngine;
use App\Services\Ocr\OcrSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OcrTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEngine(string $text = 'نص عربي مقروء'): void
    {
        $this->app->instance(OcrEngine::class, new class($text) extends OcrEngine
        {
            public array $seen = [];

            public function __construct(private string $text) {}

            public function available(): bool
            {
                return true;
            }

            public function info(): array
            {
                return ['available' => true, 'version' => '5.4.0', 'languages' => ['ara', 'eng'], 'pdf' => true];
            }

            public function recognize(string $path, string $extension, string $languages, int $timeoutSeconds, int $maxPages = 300, ?string $quality = null, ?string $searchablePdf = null): array
            {
                $this->seen[] = basename($path);
                if ($searchablePdf !== null) {
                    @mkdir(dirname($searchablePdf), 0777, true);
                    file_put_contents($searchablePdf, '%PDF-1.4 searchable copy');
                }

                return ['text' => $this->text, 'pages' => $extension === 'pdf' ? 2 : 1, 'searchable' => $searchablePdf !== null];
            }
        });
        // A quiet computer.
        $this->app->instance(LoadMonitor::class, new class extends LoadMonitor
        {
            public function sample(): array
            {
                return ['cpu' => 5.0, 'ram_free_mb' => 8000, 'disk_free_gb' => 100.0, 'active_users' => 0];
            }
        });
    }

    private function file(string $ext, array $extra = []): File
    {
        $f = File::factory()->create(['name' => "scan.$ext", 'extension' => $ext, 'path' => "ocr-test-".uniqid().".$ext", ...$extra]);
        $f->disk()->put($f->path, 'x');

        return $f->fresh();
    }

    public function test_the_work_window_crosses_midnight_and_honours_days_off(): void
    {
        Setting::setMany(['ocr_days' => '0,1,2,3,4', 'ocr_start' => '15:00', 'ocr_end' => '07:00', 'ocr_all_day_days' => '5']);
        $at = fn (string $t) => Carbon::parse($t, OcrSettings::TIMEZONE);

        $this->assertFalse(OcrSettings::inWindow($at('2026-11-08 10:00'))); // Sunday morning, office hours
        $this->assertTrue(OcrSettings::inWindow($at('2026-11-08 16:00')));  // Sunday after work
        $this->assertTrue(OcrSettings::inWindow($at('2026-11-09 06:30')));  // Monday before work (Sunday's night)
        $this->assertTrue(OcrSettings::inWindow($at('2026-11-06 11:00')));  // Friday: all day
        $this->assertFalse(OcrSettings::inWindow($at('2026-11-07 16:00'))); // Saturday evening: not a chosen day
        $this->assertFalse(OcrSettings::inWindow($at('2026-11-08 06:00'))); // Sunday 06:00 belongs to Saturday's night
        $this->assertFalse(OcrSettings::inWindow($at('2026-11-07 06:00'))); // Saturday 06:00 is Friday's night; Friday is not in the evening days
    }

    public function test_only_scans_are_queued_and_processed_off_hours(): void
    {
        Storage::fake(config('filenest.disk'));
        $this->fakeEngine();
        Setting::setMany(['ocr_start' => '00:00', 'ocr_end' => '00:00', 'ocr_days' => '0,1,2,3,4,5,6']);

        $image = $this->file('jpg');
        $scan = $this->file('pdf', []);
        $scan->forceFill(['content_indexed_at' => now(), 'content_text' => null])->save();
        $textPdf = $this->file('pdf');
        $textPdf->forceFill(['content_indexed_at' => now(), 'content_text' => str_repeat('نص موجود ', 40)])->save();
        $doc = $this->file('txt');

        $this->artisan('ocr:run')->assertSuccessful();

        $this->assertSame('done', $image->fresh()->ocr_status);
        $this->assertSame('نص عربي مقروء', File::find($image->id)->content_text);
        $this->assertSame('done', $scan->fresh()->ocr_status);
        $this->assertSame(2, $scan->fresh()->ocr_pages);
        $this->assertNull($textPdf->fresh()->ocr_status);
        $this->assertNull($doc->fresh()->ocr_status);
        $this->assertSame('running', OcrLoadSample::latest('id')->first()->state);
        $this->assertSame(2, (int) OcrLoadSample::sum('processed'));
    }

    public function test_it_waits_outside_the_hours_and_pauses_when_busy(): void
    {
        Storage::fake(config('filenest.disk'));
        $this->fakeEngine();
        $image = $this->file('png');

        // Outside the hours: queued, not processed.
        Setting::setMany(['ocr_days' => '', 'ocr_all_day_days' => '']);
        $this->artisan('ocr:run')->assertSuccessful();
        $this->assertSame('pending', $image->fresh()->ocr_status);
        $this->assertSame('outside_hours', OcrLoadSample::latest('id')->first()->state);

        // Inside the hours but more people online than allowed.
        Setting::setMany(['ocr_days' => '0,1,2,3,4,5,6', 'ocr_start' => '00:00', 'ocr_end' => '00:00', 'ocr_max_users' => '1']);
        $this->app->instance(LoadMonitor::class, new class extends LoadMonitor
        {
            public function sample(): array
            {
                return ['cpu' => 5.0, 'ram_free_mb' => 8000, 'disk_free_gb' => 100.0, 'active_users' => 3];
            }
        });
        $this->artisan('ocr:run')->assertSuccessful();
        $this->assertSame('pending', $image->fresh()->ocr_status);
        $sample = OcrLoadSample::latest('id')->first();
        $this->assertSame('busy', $sample->state);
        $this->assertStringContainsString('المستخدمين', $sample->reason);

        // Disabled: nothing at all.
        Setting::set('ocr_enabled', '0');
        $this->artisan('ocr:run')->assertSuccessful();
        $this->assertSame('disabled', OcrLoadSample::latest('id')->first()->state);
    }

    public function test_admin_page_settings_and_file_actions(): void
    {
        Storage::fake(config('filenest.disk'));
        $this->fakeEngine();
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $user = User::factory()->create();
        $a = $this->file('jpg');
        $b = $this->file('tif');

        $this->actingAs($user)->get(route('admin.ocr.index'))->assertForbidden();

        $this->actingAs($admin)->post(route('admin.ocr.queue'))->assertRedirect();
        $this->assertSame('pending', $a->fresh()->ocr_status);

        $this->actingAs($admin)->get(route('admin.ocr.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Ocr')
                ->where('counts.pending', 2)->where('engine.available', true)->has('files.data', 2));

        $this->actingAs($admin)->post(route('admin.ocr.file', ['file' => $b->id, 'action' => 'prioritize']))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.ocr.file', ['file' => $a->id, 'action' => 'skip']))->assertRedirect();
        $this->assertSame('skipped', $a->fresh()->ocr_status);
        $this->assertGreaterThanOrEqual(100, $b->fresh()->ocr_priority);

        $this->actingAs($admin)->post(route('admin.ocr.update'), [
            'ocr_enabled' => true, 'ocr_report' => true, 'ocr_days' => [0, 1, 2, 3, 4], 'ocr_all_day_days' => [5],
            'ocr_start' => '16:00', 'ocr_end' => '06:30', 'ocr_batch' => 10, 'ocr_languages' => 'ara',
            'ocr_max_cpu' => 60, 'ocr_min_ram_mb' => 1024, 'ocr_min_disk_gb' => 3, 'ocr_max_users' => 5,
            'ocr_timeout_minutes' => 5, 'ocr_max_pages' => 100, 'ocr_max_mb' => 50,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('16:00', OcrSettings::get('ocr_start'));
        $this->assertSame('0,1,2,3,4', OcrSettings::get('ocr_days'));
        $this->assertSame('ara', OcrSettings::get('ocr_languages'));

        $this->actingAs($admin)->post(route('admin.ocr.update'), ['ocr_start' => '25:00'])->assertSessionHasErrors('ocr_start');

        // Morning report to the admins.
        $this->artisan('ocr:run --force')->assertSuccessful();
        $this->assertSame('done', $b->fresh()->ocr_status);
        $this->artisan('ocr:report')->assertSuccessful();
        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('kind', 'ocr_report')->exists());
    }

    /** A small PDF with a line of text on each page (Helvetica), built by hand. */
    private function pdf(array $lines): string
    {
        $n = count($lines);
        $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($i) => (4 + 2 * $i).' 0 R', range(0, $n - 1)))."] /Count $n >>", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        foreach ($lines as $i => $line) {
            $stream = "BT /F1 40 Tf 60 650 Td ($line) Tj ET";
            $objs[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents '.(5 + 2 * $i).' 0 R >>';
            $objs[] = '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream";
        }
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $k => $o) {
            $offsets[] = strlen($out);
            $out .= ($k + 1)." 0 obj\n$o\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref'."\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out.'trailer << /Size '.(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    public function test_a_scanned_pdf_is_read_a_few_pages_at_a_time_with_the_real_engine(): void
    {
        $engine = app(OcrEngine::class);
        if (! $engine->available() || ! $engine->supports('pdf')) {
            $this->markTestSkipped('Tesseract or pdftoppm is not installed here.');
        }
        $path = tempnam(sys_get_temp_dir(), 'ocr').'.pdf';
        file_put_contents($path, $this->pdf(['FIRST PAGE HELLO', 'SECOND PAGE WORLD', 'THIRD PAGE DENTAL', 'FOURTH PAGE LIBRARY', 'FIFTH PAGE THESIS']));

        $all = $engine->recognize($path, 'pdf', 'eng', 120);
        $this->assertSame(5, $all['pages']);
        $this->assertFalse($all['partial']);
        foreach (['HELLO', 'WORLD', 'DENTAL', 'LIBRARY', 'THESIS'] as $word) {
            $this->assertStringContainsString($word, $all['text']);
        }
        // In page order.
        $this->assertLessThan(strpos($all['text'], 'THESIS'), strpos($all['text'], 'HELLO'));

        // A page limit: what was read is kept and the file is marked as read in part.
        $some = $engine->recognize($path, 'pdf', 'eng', 120, 2);
        $this->assertSame(2, $some['pages']);
        $this->assertTrue($some['partial']);
        $this->assertStringNotContainsString('DENTAL', $some['text']);
        @unlink($path);
    }

    public function test_a_recognised_scan_is_served_as_a_searchable_pdf_and_reading_again_does_not_double_the_text(): void
    {
        Storage::fake(config('filenest.disk'));
        $this->fakeEngine();
        Setting::setMany(['ocr_start' => '00:00', 'ocr_end' => '00:00', 'ocr_days' => '0,1,2,3,4,5,6']);
        $admin = User::factory()->create(['role' => 'admin']);
        $scan = $this->file('pdf', ['user_id' => $admin->id, 'mime_type' => 'application/pdf']);
        // Read before: its old recognised text must be replaced, not added to.
        $scan->forceFill(['content_indexed_at' => now(), 'content_text' => 'نص عربي مقروء', 'ocr_status' => 'pending', 'ocr_done_at' => now()->subDay()])->save();

        app(\App\Services\Ocr\OcrRunner::class)->process($scan->fresh());

        $scan = $scan->fresh();
        $this->assertSame('نص عربي مقروء', $scan->content_text);
        $this->assertNotNull($scan->searchablePdf());

        $this->actingAs($admin);
        $preview = $this->get(route('files.preview', $scan));
        $preview->assertOk();
        $this->assertSame($scan->searchablePdf(), $preview->baseResponse->getFile()->getPathname());
        $download = $this->get(route('files.download', $scan));
        $download->assertOk();
        $this->assertSame($scan->searchablePdf(), $download->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('scan.pdf', (string) $download->headers->get('content-disposition'));

        // A new version of the file never shows the old copy; deleting removes it.
        $old = $scan->searchablePdf();
        $scan->forceFill(['size' => $scan->size + 1])->save();
        $this->assertNull($scan->fresh()->searchablePdf());
        $scan->fresh()->forceDelete();
        $this->assertFileDoesNotExist($old);
    }

    public function test_the_real_engine_makes_a_searchable_copy_with_the_same_pages(): void
    {
        $engine = app(OcrEngine::class);
        if (! $engine->available() || ! $engine->supports('pdf') || $engine->qpdf() === null) {
            $this->markTestSkipped('Tesseract, pdftoppm or qpdf is not installed here.');
        }
        $path = tempnam(sys_get_temp_dir(), 'ocr').'.pdf';
        file_put_contents($path, $this->pdf(['SEARCHABLE ONE', 'SEARCHABLE TWO']));
        $out = sys_get_temp_dir().'/searchable-'.uniqid().'.pdf';

        $result = $engine->recognize($path, 'pdf', 'eng', 120, 300, 'fast', $out);

        $this->assertTrue($result['searchable']);
        $this->assertFileExists($out);
        $this->assertSame(2, $engine->pageCount($out));
        $this->assertStringContainsString('SEARCHABLE', $result['text']);
        @unlink($path);
        @unlink($out);
    }

    public function test_the_accurate_model_folder_is_used_when_installed_and_skipped_when_not(): void
    {
        $engine = app(OcrEngine::class);
        if (! $engine->available() || ! $engine->supports('pdf')) {
            $this->markTestSkipped('Tesseract or pdftoppm is not installed here.');
        }
        $path = tempnam(sys_get_temp_dir(), 'ocr').'.pdf';
        file_put_contents($path, $this->pdf(['ACCURATE MODEL PAGE']));

        // Not installed: «best» quietly reads like «fast».
        config(['services.ocr.tessdata_best' => sys_get_temp_dir().'/no-such-tessdata']);
        $this->assertNull($engine->bestDir());
        $this->assertStringContainsString('ACCURATE', $engine->recognize($path, 'pdf', 'eng', 120, 300, 'best')['text']);

        // Installed: the folder is passed to Tesseract (here a copy of the English model).
        preg_match('/"([^"]+)"/', (string) shell_exec('tesseract --list-langs 2>&1'), $m);
        $system = rtrim($m[1] ?? '', '/');
        if (! is_file("$system/eng.traineddata")) {
            $this->markTestSkipped('English model not found.');
        }
        $dir = sys_get_temp_dir().'/tessdata-best-'.uniqid();
        mkdir($dir);
        copy("$system/eng.traineddata", "$dir/eng.traineddata");
        copy("$system/eng.traineddata", "$dir/ara.traineddata");
        config(['services.ocr.tessdata_best' => $dir]);
        $this->assertSame($dir, $engine->bestDir());
        $this->assertStringContainsString('ACCURATE', $engine->recognize($path, 'pdf', 'eng', 120, 300, 'best')['text']);

        // A broken folder shows it really is the one read from.
        unlink("$dir/eng.traineddata");
        $this->expectException(\RuntimeException::class);
        try {
            $engine->recognize($path, 'pdf', 'eng', 120, 300, 'best');
        } finally {
            @unlink("$dir/ara.traineddata");
            @rmdir($dir);
            @unlink($path);
        }
    }
}

