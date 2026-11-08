<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function currentOtpFor(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    /**
     * All 2FA setup/management routes require a recently-confirmed
     * password (see routes/auth.php's password.confirm group), so tests
     * that exercise them act as a user whose session already has that
     * confirmation, exactly as a real browser would after visiting
     * /confirm-password.
     */
    private function asConfirmedUser(User $user): TestCase
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_two_factor_setup_routes_require_a_recently_confirmed_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('two-factor.enable'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_enabling_generates_an_unconfirmed_secret_and_a_qr_code_uri(): void
    {
        $user = User::factory()->create();

        $response = $this->asConfirmedUser($user)->post(route('two-factor.enable'));

        $response->assertRedirect();
        $response->assertSessionHas('twoFactorQrCodeUri');
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_confirming_with_the_correct_code_activates_two_factor_and_returns_recovery_codes(): void
    {
        $user = User::factory()->create();
        $this->asConfirmedUser($user)->post(route('two-factor.enable'));
        $user->refresh();

        $code = $this->currentOtpFor($user->two_factor_secret);

        $response = $this->asConfirmedUser($user)->post(route('two-factor.confirm'), ['code' => $code]);

        $response->assertRedirect();
        $response->assertSessionHas('twoFactorRecoveryCodes');
        $this->assertCount(8, session('twoFactorRecoveryCodes'));

        $user->refresh();
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
    }

    public function test_confirming_with_a_wrong_code_fails_and_does_not_activate(): void
    {
        $user = User::factory()->create();
        $this->asConfirmedUser($user)->post(route('two-factor.enable'));

        $this->asConfirmedUser($user)
            ->post(route('two-factor.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_login_with_two_factor_enabled_requires_the_challenge_step_instead_of_logging_in_directly(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->asConfirmedUser($user)->post(route('two-factor.enable'));
        $user->refresh();
        $code = $this->currentOtpFor($user->two_factor_secret);
        $this->asConfirmedUser($user)->post(route('two-factor.confirm'), ['code' => $code]);
        auth()->logout();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertTrue(session()->has('login.id'));
    }

    public function test_the_two_factor_challenge_completes_login_with_a_correct_code(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->asConfirmedUser($user)->post(route('two-factor.enable'));
        $user->refresh();
        $code = $this->currentOtpFor($user->two_factor_secret);
        $this->asConfirmedUser($user)->post(route('two-factor.confirm'), ['code' => $code]);
        auth()->logout();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password123']);

        $freshCode = $this->currentOtpFor($user->fresh()->two_factor_secret);
        $response = $this->post(route('two-factor.challenge'), ['code' => $freshCode]);

        $response->assertRedirect(route('exams.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_two_factor_challenge_rejects_a_wrong_code(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->asConfirmedUser($user)->post(route('two-factor.enable'));
        $user->refresh();
        $code = $this->currentOtpFor($user->two_factor_secret);
        $this->asConfirmedUser($user)->post(route('two-factor.confirm'), ['code' => $code]);
        auth()->logout();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password123']);

        $this->post(route('two-factor.challenge'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_recovery_code_completes_login_and_is_then_consumed(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->asConfirmedUser($user)->post(route('two-factor.enable'));
        $user->refresh();
        $code = $this->currentOtpFor($user->two_factor_secret);
        $this->asConfirmedUser($user)->post(route('two-factor.confirm'), ['code' => $code]);
        $recoveryCode = session('twoFactorRecoveryCodes')[0];
        auth()->logout();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password123']);

        $response = $this->post(route('two-factor.challenge'), ['recovery_code' => $recoveryCode]);
        $response->assertRedirect(route('exams.index'));
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->assertNotContains($recoveryCode, $user->fresh()->recoveryCodes());

        // The same recovery code cannot be used a second time.
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password123']);
        $this->post(route('two-factor.challenge'), ['recovery_code' => $recoveryCode])
            ->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_a_user_without_two_factor_enabled_logs_in_directly(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('exams.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_disabling_two_factor_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->asConfirmedUser($user)->post(route('two-factor.enable'));
        $user->refresh();
        $code = $this->currentOtpFor($user->two_factor_secret);
        $this->asConfirmedUser($user)->post(route('two-factor.confirm'), ['code' => $code]);

        $this->asConfirmedUser($user)
            ->delete(route('two-factor.disable'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());

        $this->asConfirmedUser($user)
            ->delete(route('two-factor.disable'), ['password' => 'password123'])
            ->assertRedirect();
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_regenerating_recovery_codes_replaces_the_old_set(): void
    {
        $user = User::factory()->create();
        $this->asConfirmedUser($user)->post(route('two-factor.enable'));
        $user->refresh();
        $code = $this->currentOtpFor($user->two_factor_secret);
        $this->asConfirmedUser($user)->post(route('two-factor.confirm'), ['code' => $code]);
        $originalCodes = $user->fresh()->recoveryCodes();

        $response = $this->asConfirmedUser($user)->post(route('two-factor.recovery-codes'));

        $response->assertSessionHas('twoFactorRecoveryCodes');
        $newCodes = $user->fresh()->recoveryCodes();
        $this->assertCount(8, $newCodes);
        $this->assertNotEquals($originalCodes, $newCodes);
    }

    public function test_the_two_factor_secret_and_recovery_codes_are_never_serialized_to_the_frontend(): void
    {
        $user = User::factory()->create();
        $this->asConfirmedUser($user)->post(route('two-factor.enable'));
        $user->refresh();
        $code = $this->currentOtpFor($user->two_factor_secret);
        $this->asConfirmedUser($user)->post(route('two-factor.confirm'), ['code' => $code]);

        $array = $user->fresh()->toArray();

        $this->assertArrayNotHasKey('two_factor_secret', $array);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $array);
    }
}
