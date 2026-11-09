<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_and_a_user_switch_the_language(): void
    {
        // A guest on the sign-in page: kept in the session.
        $this->post(route('locale.switch', 'en'))->assertRedirect();
        $this->get(route('login'))->assertInertia(fn (AssertableInertia $p) => $p->where('appSettings.locale', 'en'));
        $this->post(route('locale.switch', 'xx'))->assertNotFound();

        // A user: kept on the account.
        $user = User::factory()->create(['locale' => 'ar']);
        $this->actingAs($user)->post(route('locale.switch', 'en'))->assertRedirect();
        $this->assertSame('en', $user->fresh()->locale);
    }
}
