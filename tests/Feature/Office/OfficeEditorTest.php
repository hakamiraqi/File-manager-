<?php

namespace Tests\Feature\Office;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Office\OnlyOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OfficeEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filenest.disk'));
        Storage::fake('local');
        config(['services.onlyoffice.secret' => 'test-secret-123', 'services.onlyoffice.app_internal_url' => 'http://filenest-web']);
    }

    private function doc(User $owner, ?Folder $folder = null, string $ext = 'docx'): File
    {
        $f = File::factory()->create([
            'user_id' => $owner->id, 'folder_id' => $folder?->id, 'name' => "report.$ext",
            'extension' => $ext, 'path' => "{$owner->id}/x.$ext", 'mime_type' => 'application/octet-stream', 'size' => 3,
        ]);
        $f->disk()->put($f->path, 'old');

        return $f->fresh();
    }

    public function test_the_owner_opens_a_document_to_edit_with_a_signed_config_and_others_only_view_or_not_at_all(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $folder = Folder::factory()->create(['user_id' => $owner->id]);
        $file = $this->doc($owner, $folder);
        $office = app(OnlyOffice::class);

        $this->actingAs($owner)->get(route('office.edit', $file))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Office/Editor')
                ->where('canEdit', true)
                ->where('config.documentType', 'word')
                ->where('config.editorConfig.mode', 'edit')
                ->where('config.document.url', fn ($url) => str_starts_with($url, 'http://filenest-web/api/office-link/files/'.$file->id.'/download?') && str_contains($url, 'signature='))
                ->where('config.token', function ($token) use ($office) {
                    $payload = $office->verify($token);

                    return $payload['document']['title'] === 'report.docx' && ! isset($payload['token']);
                }));

        // A reader of the folder (role without upload rights) gets the viewer.
        \App\Models\RolePermission::updateOrCreate(['role' => 'user', 'action' => 'view'], ['allowed' => true]);
        \App\Models\RolePermission::updateOrCreate(['role' => 'user', 'action' => 'download'], ['allowed' => true]);
        $reader = User::factory()->create(['role' => 'user']);
        $reader->assignedFolders()->sync([$folder->id]);
        $this->actingAs($reader)->get(route('office.edit', $file))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', false)->where('config.editorConfig.mode', 'view'));

        // Someone without access to the folder gets nothing.
        $this->actingAs(User::factory()->create())->get(route('office.edit', $file))->assertForbidden();

        // No secret set: the editor is off.
        config(['services.onlyoffice.secret' => null]);
        $this->actingAs($owner)->get(route('office.edit', $file))->assertNotFound();
    }

    public function test_the_editor_fetches_the_file_and_saves_it_back_only_with_signed_links_and_a_valid_token(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $file = $this->doc($owner);
        $office = app(OnlyOffice::class);

        $this->get("/api/office-link/files/{$file->id}/download")->assertForbidden();
        $this->get(URL::temporarySignedRoute('office.download', now()->addHour(), ['file' => $file->id], false))->assertOk();

        $callback = URL::temporarySignedRoute('office.callback', now()->addHour(), ['file' => $file->id], false);
        Http::fake(['*' => Http::response('new content from the editor')]);

        // A forged token changes nothing.
        $this->postJson($callback, ['status' => 2, 'url' => 'http://onlyoffice/cache/x', 'token' => 'a.b.c'])->assertForbidden();
        $this->assertSame('old', $file->disk()->get($file->path));

        // Still editing: nothing to save.
        $body = ['status' => 1, 'key' => $office->key($file), 'users' => [(string) $owner->id]];
        $this->postJson($callback, $body + ['token' => $office->sign($body)])->assertOk()->assertJson(['error' => 0]);
        $this->assertSame('old', $file->disk()->get($file->path));

        // Saved: the new copy replaces the file and the old one is kept.
        $body = ['status' => 2, 'key' => $office->key($file), 'url' => 'https://files.example.org/office/cache/files/x/output.docx', 'users' => [(string) $owner->id]];
        $this->postJson($callback, $body + ['token' => $office->sign($body)])->assertOk()->assertJson(['error' => 0]);
        $this->assertSame('new content from the editor', $file->disk()->get($file->path));
        $this->assertSame(strlen('new content from the editor'), $file->fresh()->size);
        $this->assertCount(1, Storage::disk('local')->files('versions/'.$file->id));
        $this->assertNotSame($office->key($file), $office->key($file->fresh()));
        $this->assertDatabaseHas('activity_logs', ['action' => 'file.updated', 'subject_id' => $file->id, 'user_id' => $owner->id]);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://onlyoffice/cache/files/x/output.docx'));

        // A token in the Authorization header (the editor's other way) works too.
        $body = ['status' => 6, 'key' => 'k', 'url' => 'http://onlyoffice/cache/y', 'users' => [(string) $owner->id]];
        $this->postJson($callback, $body, ['Authorization' => 'Bearer '.$office->sign(['payload' => $body])])->assertOk();
        $this->assertCount(2, Storage::disk('local')->files('versions/'.$file->id));
    }

    public function test_a_new_word_excel_or_powerpoint_document_opens_in_the_editor(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $folder = Folder::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('office.create'), ['type' => 'xlsx', 'folder_id' => $folder->id]);
        $file = File::where('folder_id', $folder->id)->firstOrFail();
        $response->assertRedirect(route('office.edit', $file));
        $this->assertSame('جدول جديد.xlsx', $file->name);
        $this->assertSame('xlsx', $file->extension);
        $this->assertTrue($file->disk()->exists($file->path));

        $this->post(route('office.create'), ['type' => 'exe'])->assertSessionHasErrors('type');
    }
}
