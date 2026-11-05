<?php

namespace Tests\Feature;

use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitors_get_the_login_form_when_guest_access_is_off(): void
    {
        Setting::set('guest_access_enabled', '0');

        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_visitors_land_on_public_folders_when_guest_access_is_on(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Setting::set('guest_access_enabled', '1');

        $this->get('/')->assertRedirect(route('guest.index'));
        $this->get(route('guest.index'))->assertOk();
    }
}
