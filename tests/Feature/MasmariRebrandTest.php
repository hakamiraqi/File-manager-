<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** «مسماري» (2026-11-21): the old FileNest defaults become the new name and skin; admin choices stay. */
class MasmariRebrandTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_21_100000_rename_site_to_masmari.php');
    }

    public function test_old_defaults_are_replaced(): void
    {
        Setting::setMany(['site_name' => 'FileNest', 'active_skin' => 'indigo']);
        $this->migration()->up();
        $this->assertSame('مسماري', Setting::get('site_name'));
        $this->assertSame('clay', Setting::get('active_skin'));

        $this->migration()->down();
        $this->assertSame('FileNest', Setting::get('site_name'));
        $this->assertSame('indigo', Setting::get('active_skin'));
    }

    public function test_missing_settings_get_the_new_name(): void
    {
        Setting::query()->whereIn('key', ['site_name', 'active_skin'])->delete();
        \Illuminate\Support\Facades\Cache::forget(Setting::CACHE_KEY);
        $this->migration()->up();
        $this->assertSame('مسماري', Setting::get('site_name'));
        $this->assertSame('clay', Setting::get('active_skin'));
    }

    public function test_an_admins_own_choice_is_kept(): void
    {
        Setting::setMany(['site_name' => 'مكتبة الكلية', 'active_skin' => 'teal']);
        $this->migration()->up();
        $this->assertSame('مكتبة الكلية', Setting::get('site_name'));
        $this->assertSame('teal', Setting::get('active_skin'));
    }

    public function test_the_page_shows_the_new_name_and_logo(): void
    {
        Setting::setMany(['site_name' => 'مسماري', 'active_skin' => 'clay']);
        $this->withoutVite()->get('/login')->assertOk()->assertSee('<title inertia>مسماري</title>', false)->assertSee('data-skin="clay"', false);
    }
}
