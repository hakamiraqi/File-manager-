<?php

namespace Tests\Feature\FileManager;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ZipExtractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filenest.disk'));
        Queue::fake();
    }

    /** @param array<string, string> $entries */
    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zt').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            str_ends_with($name, '/') ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function upload(User $user, string $name, string $bytes, bool $extract, ?int $folderId = null): array
    {
        $init = $this->actingAs($user)->postJson(route('uploads.init'), [
            'name' => $name, 'size' => strlen($bytes), 'folder_id' => $folderId,
        ])->assertOk()->json();

        $chunk = tempnam(sys_get_temp_dir(), 'ch');
        file_put_contents($chunk, $bytes);
        $this->actingAs($user)->post(route('uploads.chunk'), [
            'identifier' => $init['identifier'],
            'index' => 0,
            'chunk' => new UploadedFile($chunk, 'chunk0', null, null, true),
        ])->assertOk();

        return $this->actingAs($user)->postJson(route('uploads.finalize'), [
            'identifier' => $init['identifier'],
            'extract' => $extract,
        ])->assertOk()->json();
    }

    public function test_uploading_a_zip_with_extract_unpacks_it_into_a_folder_and_drops_the_archive(): void
    {
        $user = User::factory()->create();
        $zip = $this->makeZip([
            'notes.txt' => 'hello',
            'lectures/week1/slides.pdf' => '%PDF-1.4 fake',
            'lectures/empty/' => '',
            'ملخص.txt' => 'عربي',
        ]);

        $response = $this->upload($user, 'course.zip', file_get_contents($zip), true);

        $this->assertNull($response['file']);
        $this->assertSame(3, $response['extracted']['files']);

        $root = Folder::where('name', 'course')->whereNull('parent_id')->firstOrFail();
        $this->assertSame($root->id, $response['extracted']['folder']['id']);
        $lectures = Folder::where('parent_id', $root->id)->where('name', 'lectures')->firstOrFail();
        $week1 = Folder::where('parent_id', $lectures->id)->where('name', 'week1')->firstOrFail();
        $this->assertTrue(Folder::where('parent_id', $lectures->id)->where('name', 'empty')->exists());

        $slides = File::where('folder_id', $week1->id)->where('name', 'slides.pdf')->firstOrFail();
        $this->assertSame('%PDF-1.4 fake', Storage::disk(config('filenest.disk'))->get($slides->path));
        $this->assertTrue(File::where('folder_id', $root->id)->where('name', 'ملخص.txt')->exists());

        // The archive itself is not kept.
        $this->assertFalse(File::where('name', 'course.zip')->exists());
        $this->assertSame(5 + 13 + strlen('عربي'), (int) $user->fresh()->storage_used_bytes);
    }

    public function test_uploading_a_zip_without_extract_keeps_it_as_a_file(): void
    {
        $user = User::factory()->create();
        $zip = $this->makeZip(['a.txt' => 'a']);

        $response = $this->upload($user, 'keep.zip', file_get_contents($zip), false);

        $this->assertSame('keep.zip', $response['file']['name']);
        $this->assertSame(0, Folder::count());
    }

    public function test_an_uploaded_zip_can_be_extracted_later_beside_itself(): void
    {
        $user = User::factory()->create();
        $parent = Folder::create(['user_id' => $user->id, 'name' => 'Docs']);
        $zip = $this->makeZip(['x/y.txt' => 'y']);
        $file = File::find($this->upload($user, 'later.zip', file_get_contents($zip), false, $parent->id)['file']['id']);

        $this->actingAs($user)->postJson(route('files.extract', $file))
            ->assertOk()
            ->assertJsonPath('extracted.files', 1);

        $root = Folder::where('parent_id', $parent->id)->where('name', 'later')->firstOrFail();
        $this->assertTrue(Folder::where('parent_id', $root->id)->where('name', 'x')->exists());
        $this->assertTrue(File::whereKey($file->id)->exists(), 'the archive is kept');
    }

    public function test_zip_slip_paths_never_leave_the_new_folder_or_the_storage_layout(): void
    {
        $user = User::factory()->create();
        $zip = $this->makeZip([
            '../../../../etc/evil.txt' => 'pwn',
            '/abs/root.txt' => 'abs',
            'C:\\win\\x.txt' => 'win',
        ]);

        $this->upload($user, 'slip.zip', file_get_contents($zip), true);

        $root = Folder::where('name', 'slip')->firstOrFail();
        foreach (File::all() as $file) {
            $this->assertMatchesRegularExpression('#^'.$user->id.'/[0-9a-f-]{36}\.txt$#', $file->path);
        }
        $this->assertTrue(File::where('name', 'evil.txt')->exists());
        $this->assertSame(['abs', 'etc', 'win'], Folder::where('parent_id', $root->id)->orderBy('name')->pluck('name')->all());
        $this->assertFalse(Folder::where('name', '..')->exists());
    }

    public function test_forbidden_types_inside_the_zip_are_skipped(): void
    {
        $user = User::factory()->create();
        $zip = $this->makeZip(['shell.php' => '<?php', 'ok.txt' => 'ok', '__MACOSX/._ok.txt' => 'junk']);

        $response = $this->upload($user, 'mixed.zip', file_get_contents($zip), true);

        $this->assertSame(1, $response['extracted']['files']);
        $this->assertSame(['shell.php'], $response['extracted']['skipped']);
        $this->assertFalse(File::where('name', 'shell.php')->exists());
    }

    public function test_a_zip_bomb_is_refused_and_kept_as_a_plain_file(): void
    {
        config(['filenest.zip_max_extracted_mb' => 1]);
        $user = User::factory()->create();
        $zip = $this->makeZip(['big.txt' => str_repeat('0', 2 * 1024 * 1024)]);

        $response = $this->upload($user, 'bomb.zip', file_get_contents($zip), true);

        $this->assertNotNull($response['extract_error']);
        $this->assertSame('bomb.zip', $response['file']['name']);
        $this->assertSame(0, Folder::count());
        $this->assertSame(1, File::count());
    }

    public function test_a_highly_compressed_entry_is_refused(): void
    {
        $user = User::factory()->create();
        $zip = $this->makeZip(['zeros.bin' => str_repeat("\0", 50 * 1024 * 1024)]);
        $file = File::find($this->upload($user, 'z.zip', file_get_contents($zip), false)['file']['id']);

        $this->actingAs($user)->postJson(route('files.extract', $file))->assertStatus(422);
        $this->assertSame(0, Folder::count());
    }

    public function test_extraction_respects_the_storage_quota(): void
    {
        $user = User::factory()->create(['storage_quota_bytes' => 1024]);
        $zip = $this->makeZip(['a.txt' => str_repeat('a', 4096)]);

        $response = $this->upload($user, 'q.zip', file_get_contents($zip), true);

        $this->assertNotNull($response['extract_error']);
        $this->assertSame(0, Folder::count());
    }

    public function test_another_users_zip_cannot_be_extracted(): void
    {
        $owner = User::factory()->create();
        $zip = $this->makeZip(['a.txt' => 'a']);
        $file = File::find($this->upload($owner, 'mine.zip', file_get_contents($zip), false)['file']['id']);

        $this->actingAs(User::factory()->create())->postJson(route('files.extract', $file))->assertForbidden();
    }

    public function test_a_non_zip_file_cannot_be_extracted(): void
    {
        $user = User::factory()->create();
        $file = File::find($this->upload($user, 'a.txt', 'hello', false)['file']['id']);

        $this->actingAs($user)->postJson(route('files.extract', $file))->assertStatus(422);
    }
}
