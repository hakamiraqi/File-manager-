<?php

namespace Tests\Feature\Admin;

use App\Models\Language;
use App\Models\TranslationOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LanguagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
    }

    public function test_the_editor_lists_keys_that_only_exist_as_code_fallbacks(): void
    {
        $this->actingAs($this->admin())->get(route('admin.translations.index', ['locale' => 'ar']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Translations')
                ->where('refLocale', 'en')
                ->has('languages', 2)
                ->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['key'] === 'security.ip_mode_off' && $r['value'] === 'معطّل')));
    }

    public function test_an_admin_adds_edits_and_deletes_a_language(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.languages.store'), [
            'code' => 'tr', 'name' => 'Türkçe', 'dir' => 'ltr', 'flag' => 'tr', 'is_active' => true,
        ])->assertRedirect(route('admin.translations.index', ['locale' => 'tr']));
        $tr = Language::where('code', 'tr')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.translations.bulk'), [
            'locale' => 'tr', 'items' => [['key' => 'nav.dashboard', 'value' => 'Dosyalarım']],
        ])->assertRedirect();
        $this->assertDatabaseHas('translation_overrides', ['locale' => 'tr', 'key' => 'nav.dashboard', 'value' => 'Dosyalarım']);

        $admin->update(['locale' => 'tr']);
        $this->actingAs($admin)->get(route('admin.translations.index'))
            // The interface is Arabic only (2026-11-09, by request), whatever the account's language.
            ->assertInertia(fn (Assert $page) => $page->where('appSettings.locales.tr.label', 'Türkçe')->where('translations', fn ($tr) => ($tr['nav.dashboard'] ?? null) !== 'Dosyalarım'));

        $this->actingAs($admin)->patch(route('admin.languages.update', $tr), [
            'name' => 'Türkçe', 'dir' => 'ltr', 'flag' => 'icon:globe', 'is_active' => false,
        ])->assertRedirect();
        $this->assertSame('icon:globe', $tr->fresh()->flag);
        $this->assertNull($admin->fresh()->locale);

        $this->actingAs($admin)->delete(route('admin.languages.destroy', $tr))->assertRedirect();
        $this->assertDatabaseMissing('languages', ['code' => 'tr']);
        $this->assertSame(0, TranslationOverride::where('locale', 'tr')->count());
    }

    public function test_builtin_and_default_languages_are_protected(): void
    {
        $admin = $this->admin();
        $ar = Language::where('code', 'ar')->firstOrFail();

        $this->actingAs($admin)->delete(route('admin.languages.destroy', $ar))->assertSessionHasErrors('language');
        $this->actingAs($admin)->patch(route('admin.languages.update', $ar), ['name' => 'العربية', 'dir' => 'rtl', 'flag' => 'iq', 'is_active' => false])
            ->assertSessionHasErrors('is_active');
        $this->actingAs($admin)->post(route('admin.languages.store'), ['code' => 'ar', 'name' => 'x', 'dir' => 'rtl', 'is_active' => true])
            ->assertSessionHasErrors('code');
    }

    public function test_users_can_pick_an_added_language_but_not_an_unknown_one(): void
    {
        Language::create(['code' => 'fr', 'name' => 'Français', 'dir' => 'ltr', 'flag' => 'fr', 'is_active' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.preferences'), ['locale' => 'fr'])->assertSessionHasNoErrors();
        $this->assertSame('fr', $user->fresh()->locale);
        $this->actingAs($user)->patch(route('profile.preferences'), ['locale' => 'xx'])->assertSessionHasErrors('locale');
    }
}
