<?php

namespace Tests\Feature\Pdf;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Pdf\PdfTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PdfToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filenest.disk'));
        if (! app(PdfTools::class)->qpdf()) {
            $this->markTestSkipped('qpdf is not installed here.');
        }
        $this->owner = User::factory()->create(['role' => 'admin']);
        $this->folder = Folder::factory()->create(['user_id' => $this->owner->id]);
    }

    /** A small real PDF: one line of text per page. */
    private function pdf(int $pages, string $name = 'doc.pdf'): File
    {
        $objs = ['<< /Type /Catalog /Pages 2 0 R >>', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $kids = [];
        for ($i = 1; $i <= $pages; $i++) {
            $stream = "BT /F1 24 Tf 72 720 Td (Hello page {$i}) Tj ET";
            $objs[] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
            $content = count($objs);
            $objs[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {$content} 0 R >>";
            $kids[] = count($objs).' 0 R';
        }
        $objs[1] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>';
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n{$o}\nendobj\n";
        }
        $x = strlen($out);
        $out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }
        $out .= 'trailer << /Size '.(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n{$x}\n%%EOF\n";

        $f = File::factory()->create([
            'user_id' => $this->owner->id, 'folder_id' => $this->folder->id, 'name' => $name, 'extension' => 'pdf',
            'path' => $this->owner->id.'/'.uniqid().'.pdf', 'mime_type' => 'application/pdf', 'size' => strlen($out),
        ]);
        $f->disk()->put($f->path, $out);

        return $f->fresh();
    }

    private function pages(int $fileId): int
    {
        $f = File::findOrFail($fileId);

        return app(PdfTools::class)->pageCount($f->disk()->path($f->path));
    }

    private function text(int $fileId): string
    {
        $f = File::findOrFail($fileId);

        return app(PdfTools::class)->toTextString($f->disk()->path($f->path));
    }

    public function test_the_tools_page_lists_what_this_server_can_do(): void
    {
        $f = $this->pdf(1);
        $this->actingAs($this->owner)->get(route('pdf-tools.index', ['files' => [$f->id], 'tool' => 'merge']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('PdfTools/Index')->where('capabilities.merge', true)->where('tool', 'merge')->where('files.0.id', $f->id));
        $this->getJson(route('pdf-tools.files', ['kinds' => 'pdf']))->assertOk()->assertJsonPath('files.0.id', $f->id);
    }

    public function test_merge_split_and_organize_make_new_files_next_to_the_original(): void
    {
        $a = $this->pdf(2, 'a.pdf');
        $b = $this->pdf(3, 'b.pdf');
        $before = $a->disk()->get($a->path);

        $r = $this->actingAs($this->owner)->postJson(route('pdf-tools.run', 'merge'), ['files' => [$a->id, $b->id]])->assertOk();
        $merged = $r->json('files.0');
        $this->assertSame('a (مدمج).pdf', $merged['name']);
        $this->assertSame($this->folder->id, $merged['folder_id']);
        $this->assertSame(5, $this->pages($merged['id']));
        $this->assertSame($before, $a->disk()->get($a->path));

        $r = $this->postJson(route('pdf-tools.run', 'split'), ['files' => [$b->id], 'mode' => 'ranges', 'ranges' => '1, 2-z'])->assertOk();
        $this->assertCount(2, $r->json('files'));
        $this->assertSame(1, $this->pages($r->json('files.0.id')));
        $this->assertSame(2, $this->pages($r->json('files.1.id')));
        $this->postJson(route('pdf-tools.run', 'split'), ['files' => [$b->id], 'mode' => 'ranges', 'ranges' => '2-9'])->assertStatus(422);

        $r = $this->postJson(route('pdf-tools.run', 'organize'), ['files' => [$b->id], 'pages' => [[3, 90], [1, 0]]])->assertOk();
        $id = $r->json('files.0.id');
        $this->assertSame(2, $this->pages($id));
        $this->assertStringContainsString('Hello page 1', $this->text($id));
    }

    public function test_protect_then_unlock_with_the_password(): void
    {
        $f = $this->pdf(1);
        $r = $this->actingAs($this->owner)->postJson(route('pdf-tools.run', 'protect'), ['files' => [$f->id], 'password' => 'سر123', 'deny' => ['print']])->assertOk();
        $locked = File::findOrFail($r->json('files.0.id'));
        $this->assertTrue(app(PdfTools::class)->isEncrypted($locked->disk()->path($locked->path)));

        $this->postJson(route('pdf-tools.run', 'unlock'), ['files' => [$locked->id], 'password' => 'wrong'])->assertStatus(422)->assertJsonPath('message', 'كلمة المرور غير صحيحة.');
        $r = $this->postJson(route('pdf-tools.run', 'unlock'), ['files' => [$locked->id], 'password' => 'سر123'])->assertOk();
        $open = File::findOrFail($r->json('files.0.id'));
        $this->assertFalse(app(PdfTools::class)->isEncrypted($open->disk()->path($open->path)));
    }

    public function test_page_numbers_rotate_and_text(): void
    {
        $f = $this->pdf(3);
        $tools = app(PdfTools::class);
        if (! $tools->poppler('pdfinfo') || ! $tools->poppler('pdftotext')) {
            $this->markTestSkipped('Poppler is not installed here.');
        }
        $r = $this->actingAs($this->owner)->postJson(route('pdf-tools.run', 'numbers'), ['files' => [$f->id], 'position' => 'bottom-right', 'format' => 'n/N', 'start' => 1])->assertOk();
        $this->assertStringContainsString('2 / 3', $this->text($r->json('files.0.id')));

        $r = $this->postJson(route('pdf-tools.run', 'rotate'), ['files' => [$f->id], 'degrees' => 90, 'which' => 'odd'])->assertOk();
        $this->assertSame(3, $this->pages($r->json('files.0.id')));

        $r = $this->postJson(route('pdf-tools.run', 'to_text'), ['files' => [$f->id]])->assertOk();
        $txt = File::findOrFail($r->json('files.0.id'));
        $this->assertSame('txt', $txt->extension);
        $this->assertStringContainsString('Hello page 3', $txt->disk()->get($txt->path));

        $r = $this->postJson(route('pdf-tools.run', 'to_word'), ['files' => [$f->id]])->assertOk();
        $this->assertSame('docx', $r->json('files.0.extension'));
    }

    public function test_watermark_and_images(): void
    {
        $tools = app(PdfTools::class);
        if (! $tools->magick() || ! $tools->pdftoppm()) {
            $this->markTestSkipped('ImageMagick or Poppler is not installed here.');
        }
        $f = $this->pdf(2);
        $png = UploadedFile::fake()->image('mark.png', 600, 800);
        $r = $this->actingAs($this->owner)->post(route('pdf-tools.run', 'watermark'), ['files' => [$f->id], 'mark' => $png, 'pages' => 'all'], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(2, $this->pages($r->json('files.0.id')));

        $r = $this->postJson(route('pdf-tools.run', 'to_images'), ['files' => [$f->id], 'format' => 'png', 'dpi' => 50])->assertOk();
        $zip = File::findOrFail($r->json('files.0.id'));
        $this->assertSame('zip', $zip->extension);
        $z = new \ZipArchive;
        $z->open($zip->disk()->path($zip->path));
        $this->assertSame(2, $z->numFiles);
    }

    public function test_others_cannot_use_files_they_cannot_see_and_readers_cannot_create(): void
    {
        $f = $this->pdf(1);
        $stranger = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $stranger->update(['role' => 'user']);
        $this->actingAs($stranger)->postJson(route('pdf-tools.run', 'compress'), ['files' => [$f->id]])->assertForbidden();
        $this->assertSame(1, File::count());
    }
}
