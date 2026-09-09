<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\Folder;
use App\Models\Setting;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;
use ZipArchive;

class DocumentIndexingTest extends TestCase
{
    use RefreshDatabase;

    private function uploadFile(User $user, string $localPath, string $uploadName): array
    {
        $content = file_get_contents($localPath);

        $init = $this->actingAs($user)->postJson(route('uploads.init'), [
            'name' => $uploadName,
            'size' => strlen($content),
        ])->assertOk()->json();

        $this->actingAs($user)->post(route('uploads.chunk'), [
            'identifier' => $init['identifier'],
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk0', $content),
        ])->assertOk();

        return $this->actingAs($user)->postJson(route('uploads.finalize'), [
            'identifier' => $init['identifier'],
        ])->assertOk()->json();
    }

    public function test_uploading_a_pdf_extracts_and_stores_its_text(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();

        $finalize = $this->uploadFile($user, $this->makePdfFixture('Hello FileNest Library'), 'brochure.pdf');

        $file = File::find($finalize['file']['id']);
        $this->assertStringContainsString('Hello FileNest Library', $file->content_text);
        $this->assertNotNull($file->content_indexed_at);
    }

    public function test_uploading_a_docx_extracts_and_stores_its_text(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();

        $finalize = $this->uploadFile($user, $this->makeDocxFixture('A confidential quarterly report'), 'report.docx');

        $file = File::find($finalize['file']['id']);
        $this->assertStringContainsString('A confidential quarterly report', $file->content_text);
    }

    public function test_uploading_a_pptx_extracts_and_stores_its_text(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();

        $finalize = $this->uploadFile($user, $this->makePptxFixture('Roadmap slide about growth'), 'deck.pptx');

        $file = File::find($finalize['file']['id']);
        $this->assertStringContainsString('Roadmap slide about growth', $file->content_text);
    }

    public function test_a_plain_text_file_is_indexed_verbatim(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($path, 'The invoice total is nine hundred dollars');

        $finalize = $this->uploadFile($user, $path, 'notes.txt');

        $file = File::find($finalize['file']['id']);
        $this->assertStringContainsString('nine hundred dollars', $file->content_text);
    }

    public function test_search_finds_a_file_by_its_document_content_not_just_its_name(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();

        $this->uploadFile($user, $this->makePdfFixture('The secret ingredient is cinnamon'), 'untitled.pdf');

        $response = $this->actingAs($user)->get(route('dashboard', ['q' => 'cinnamon']));

        $response->assertInertia(fn ($page) => $page
            ->component('FileManager/Index')
            ->has('files', 1)
            ->where('files.0.name', 'untitled.pdf')
            ->where('files.0.content_snippet', fn ($snippet) => str_contains($snippet, 'cinnamon'))
        );
    }

    public function test_content_text_is_never_sent_to_the_browser_directly(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $this->uploadFile($user, $this->makePdfFixture('This exact sentence must stay server-side only'), 'doc.pdf');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertDontSee('must stay server-side only');
    }

    public function test_indexing_is_skipped_when_disabled(): void
    {
        Setting::set('content_indexing_enabled', false);
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();

        $finalize = $this->uploadFile($user, $this->makePdfFixture('Should not be indexed'), 'skip.pdf');

        $file = File::find($finalize['file']['id']);
        $this->assertNull($file->content_text);
    }

    public function test_indexing_skips_files_larger_than_the_configured_cap(): void
    {
        Setting::set('content_indexing_max_mb', 1);
        $user = User::factory()->create();
        Storage::fake(config('filenest.disk'));

        $file = File::factory()->create([
            'user_id' => $user->id,
            'extension' => 'txt',
            'size' => 2 * 1024 * 1024,
            'path' => 'huge.txt',
        ]);
        Storage::disk(config('filenest.disk'))->put('huge.txt', 'irrelevant content');

        (new \App\Jobs\ExtractFileText($file->id))->handle(app(DocumentTextExtractor::class));

        $this->assertNull($file->fresh()->content_text);
    }

    public function test_copying_a_file_reuses_the_already_extracted_text_instead_of_reparsing(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $folder = Folder::factory()->create(['user_id' => $user->id]);

        $file = File::factory()->create(['user_id' => $user->id]);
        Storage::disk(config('filenest.disk'))->put($file->path, 'irrelevant');
        $file->forceFill(['content_text' => 'previously extracted wisdom', 'content_indexed_at' => now()])->save();

        $this->actingAs($user)->post(route('files.copy', $file->id), ['folder_id' => $folder->id])->assertRedirect();

        $copy = File::where('id', '!=', $file->id)->where('folder_id', $folder->id)->first();
        $this->assertSame('previously extracted wisdom', $copy->content_text);
    }

    private function makePdfFixture(string $text): string
    {
        $stream = "BT /F1 24 Tf 10 100 Td ({$text}) Tj ET";
        $objs = [
            1 => "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n",
            2 => "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n",
            3 => "3 0 obj<</Type/Page/Parent 2 0 R/Resources<</Font<</F1 4 0 R>>>>/MediaBox[0 0 200 200]/Contents 5 0 R>>endobj\n",
            4 => "4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n",
            5 => "5 0 obj<</Length ".strlen($stream).">>\nstream\n{$stream}\nendstream\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objs as $num => $content) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $content;
        }
        $xrefStart = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objs) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objs); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer<</Size '.(count($objs) + 1)."/Root 1 0 R>>\n";
        $pdf .= "startxref\n{$xrefStart}\n%%EOF";

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);

        return $path;
    }

    private function makeDocxFixture(string $text): string
    {
        $phpWord = new PhpWord;
        $phpWord->addSection()->addText($text);

        $path = tempnam(sys_get_temp_dir(), 'docx');
        WordIOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    private function makePptxFixture(string $text): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pptx');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString(
            'ppt/slides/slide1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            .'<p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>'.$text.'</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>'
        );
        $zip->close();

        return $path;
    }
}
