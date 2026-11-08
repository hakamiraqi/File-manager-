<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorApiTest extends TestCase
{
    use RefreshDatabase;

    private function currentOtpFor(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    private function enableTwoFactorFor(User $user, string $password = 'password'): array
    {
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/user/two-factor-authentication', ['password' => $password]);
        $secret = $user->fresh()->two_factor_secret;
        $code = $this->currentOtpFor($secret);
        $confirm = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/user/confirmed-two-factor-authentication', ['password' => $password, 'code' => $code])
            ->json();

        return ['secret' => $secret, 'recovery_codes' => $confirm['recovery_codes']];
    }

    public function test_enabling_via_the_api_returns_a_secret_and_qr_code_uri(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/user/two-factor-authentication', ['password' => 'password']);

        $response->assertOk();
        $response->assertJsonStructure(['secret', 'qr_code_uri']);
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_enabling_without_the_correct_current_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/user/two-factor-authentication', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_confirming_via_the_api_activates_two_factor_and_returns_recovery_codes(): void
    {
        $user = User::factory()->create();
        $data = $this->enableTwoFactorFor($user);

        $this->assertCount(8, $data['recovery_codes']);
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_login_with_two_factor_enabled_returns_a_pending_token_instead_of_an_api_token(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->enableTwoFactorFor($user, 'password123');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJson(['two_factor_required' => true]);
        $response->assertJsonStructure(['two_factor_token']);
        $this->assertArrayNotHasKey('token', $response->json());
    }

    public function test_the_two_factor_token_completes_login_with_a_correct_totp_code(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $data = $this->enableTwoFactorFor($user, 'password123');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json();

        $code = $this->currentOtpFor($data['secret']);
        $response = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $login['two_factor_token'],
            'code' => $code,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['user', 'token']);
    }

    public function test_the_two_factor_challenge_rejects_a_wrong_code(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->enableTwoFactorFor($user, 'password123');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json();

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $login['two_factor_token'],
            'code' => '000000',
        ])->assertStatus(422);
    }

    public function test_a_recovery_code_completes_login_via_the_api_and_is_then_consumed(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $data = $this->enableTwoFactorFor($user, 'password123');
        $recoveryCode = $data['recovery_codes'][0];

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json();

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $login['two_factor_token'],
            'recovery_code' => $recoveryCode,
        ])->assertOk();

        $this->assertNotContains($recoveryCode, $user->fresh()->recoveryCodes());

        $login2 = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->json();

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $login2['two_factor_token'],
            'recovery_code' => $recoveryCode,
        ])->assertStatus(422);
    }

    public function test_a_tampered_two_factor_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->enableTwoFactorFor($user, 'password123');

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => 'not-a-real-token',
            'code' => '123456',
        ])->assertStatus(422);
    }

    public function test_login_without_two_factor_enabled_still_issues_a_token_directly(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJson(['two_factor_required' => false]);
        $response->assertJsonStructure(['token']);
    }

    public function test_disabling_two_factor_via_the_api_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->enableTwoFactorFor($user, 'password123');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/user/two-factor-authentication', ['password' => 'wrong-password'])
            ->assertStatus(422);
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/user/two-factor-authentication', ['password' => 'password123'])
            ->assertOk();
        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_regenerating_recovery_codes_via_the_api_replaces_the_old_set(): void
    {
        $user = User::factory()->create();
        $data = $this->enableTwoFactorFor($user);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/user/two-factor-recovery-codes', ['password' => 'password']);

        $response->assertOk();
        $newCodes = $response->json('recovery_codes');
        $this->assertCount(8, $newCodes);
        $this->assertNotEquals($data['recovery_codes'], $newCodes);
    }

    public function test_regenerating_recovery_codes_without_the_correct_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactorFor($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/user/two-factor-recovery-codes', ['password' => 'wrong-password'])
            ->assertStatus(422);
    }
}
