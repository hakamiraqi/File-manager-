<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OfflineExportTest extends TestCase
{
    use RefreshDatabase;

    private string $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->probe = storage_path('app/filemanager/offline-export-test');
        File::ensureDirectoryExists($this->probe);
        file_put_contents($this->probe.'/a.pdf', str_repeat('A', 1500));
        file_put_contents($this->probe.'/b.jpg', random_bytes(70000));
        file_put_contents($this->probe.'/empty.txt', '');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->probe);
        File::deleteDirectory(storage_path('app/private/offline-export'));
        parent::tearDown();
    }

    public function test_the_main_admin_downloads_the_site_as_tar_parts_that_resume(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $this->actingAs($admin)->get(route('admin.offline-export'))->assertOk()->assertSee('FileNest-part-1.tar', false)->assertDontSee('FileNest-part-2', false);

        $whole = $this->actingAs($admin)->get(route('admin.offline-export.part', 1));
        $whole->assertOk()->assertHeader('Accept-Ranges', 'bytes');
        $tar = $whole->streamedContent();
        $this->assertSame((int) $whole->headers->get('Content-Length'), strlen($tar));
        $this->assertSame(0, strlen($tar) % 512);

        // It is a real tar: every file comes back whole, under FileNestBackups/.
        $path = tempnam(sys_get_temp_dir(), 'exp').'.tar';
        file_put_contents($path, $tar);
        $out = sys_get_temp_dir().'/exp-'.uniqid();
        (new \PharData($path))->extractTo($out);
        $this->assertSame(file_get_contents($this->probe.'/b.jpg'), file_get_contents("$out/FileNestBackups/uploads/filemanager/offline-export-test/b.jpg"));
        $this->assertSame(str_repeat('A', 1500), file_get_contents("$out/FileNestBackups/uploads/filemanager/offline-export-test/a.pdf"));
        $this->assertFileExists("$out/FileNestBackups/uploads/filemanager/offline-export-test/empty.txt");
        $this->assertNotEmpty(glob("$out/FileNestBackups/*_offline.zip"));

        // A download cut off carries on from a byte: the pieces add up to the same bytes.
        $etag = $whole->headers->get('ETag');
        foreach ([1, 511, 512, 2000, 40000, strlen($tar) - 700] as $cut) {
            $rest = $this->actingAs($admin)->withHeaders(['Range' => "bytes=$cut-", 'If-Range' => $etag])->get(route('admin.offline-export.part', 1));
            $rest->assertStatus(206)->assertHeader('Content-Range', "bytes $cut-".(strlen($tar) - 1).'/'.strlen($tar));
            $this->assertSame(substr($tar, $cut), $rest->streamedContent(), "resume at $cut");
        }
        $mid = $this->actingAs($admin)->withHeaders(['Range' => 'bytes=1000-70999'])->get(route('admin.offline-export.part', 1));
        $this->assertSame(substr($tar, 1000, 70000), $mid->streamedContent());

        File::deleteDirectory($out);
        @unlink($path);
    }

    public function test_only_the_main_admin_can_export(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => false]);
        $this->actingAs($admin)->get(route('admin.offline-export'))->assertForbidden();
        $this->assertContains($this->actingAs(User::factory()->create())->get(route('admin.offline-export.part', 1))->status(), [302, 403]);
    }
}
