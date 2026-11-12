<?php

namespace Tests\Feature\Reader;

use App\Models\File;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReaderAiTest extends TestCase
{
    use RefreshDatabase;

    private function file(User $owner, ?string $text = 'الخلية هي الوحدة الأساسية للحياة.'): File
    {
        return File::factory()->create([
            'user_id' => $owner->id, 'name' => 'biology.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf',
            'path' => $owner->id.'/b.pdf', 'size' => 10, 'content_text' => $text, 'content_indexed_at' => now(),
        ]);
    }

    public function test_the_assistant_summarises_translates_and_answers_with_the_saved_key(): void
    {
        Setting::set('ai_api_key', Crypt::encryptString('sk-ant-test'));
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'ملخص قصير']]])]);
        $owner = User::factory()->create(['role' => 'admin']);
        $f = $this->file($owner);
        $this->actingAs($owner);

        $this->postJson(route('reader.ai', $f), ['action' => 'summarize', 'scope' => 'file'])->assertOk()->assertJsonPath('text', 'ملخص قصير');
        // The same file's summary comes from the cache the second time.
        $this->postJson(route('reader.ai', $f), ['action' => 'summarize', 'scope' => 'file'])->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->hasHeader('x-api-key', 'sk-ant-test') && str_contains($r['messages'][0]['content'], 'الخلية'));

        $this->postJson(route('reader.ai', $f), ['action' => 'translate', 'scope' => 'selection', 'text' => 'mitochondria', 'target' => 'ar'])->assertOk();
        $this->postJson(route('reader.ai', $f), ['action' => 'ask', 'question' => 'ما الخلية؟', 'history' => [['role' => 'user', 'content' => 'مرحبا'], ['role' => 'assistant', 'content' => 'أهلاً']]])->assertOk();
        Http::assertSent(fn ($r) => count($r['messages'] ?? []) === 5 && $r['messages'][4]['content'] === 'ما الخلية؟');
        $this->postJson(route('reader.ai', $f), ['action' => 'explain', 'scope' => 'selection'])->assertStatus(422);
    }

    public function test_it_is_off_without_a_key_and_closed_to_others(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $f = $this->file($owner);
        $this->actingAs($owner)->postJson(route('reader.ai', $f), ['action' => 'summarize', 'scope' => 'file'])->assertStatus(422);

        Setting::set('ai_api_key', Crypt::encryptString('k'));
        $this->actingAs(User::factory()->create())->postJson(route('reader.ai', $f), ['action' => 'summarize', 'scope' => 'file'])->assertForbidden();

        $empty = $this->file($owner, null);
        $this->actingAs($owner)->postJson(route('reader.ai', $empty), ['action' => 'summarize', 'scope' => 'file'])->assertStatus(422);
    }

    public function test_the_key_is_kept_encrypted_and_never_shown(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        Setting::set('ai_api_key', Crypt::encryptString('sk-ant-secret'));
        $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('settings.ai_api_key_set', true)->missing('settings.ai_api_key'));
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/settings')->assertOk()
            ->assertJsonMissingPath('settings.ai_api_key')->assertJsonPath('settings.ai_api_key_set', true);
    }
}
