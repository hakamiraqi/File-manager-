<?php

namespace Tests\Feature\Api;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_update_their_name_and_email(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/v1/profile', [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.name', 'New Name');
        $response->assertJsonPath('user.email', 'new@example.com');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_updating_email_to_one_already_taken_is_rejected(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', ['email' => 'taken@example.com'])
            ->assertStatus(422);
    }

    public function test_a_user_can_update_their_locale_preference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/v1/profile/preferences', ['locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_a_user_can_change_their_password_with_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/password', [
            'current_password' => 'old-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_changing_password_revokes_every_other_token_but_keeps_the_one_used_for_the_request(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $currentToken = $user->createToken('this-device');
        $otherToken = $user->createToken('stolen-device');

        $this->withHeader('Authorization', 'Bearer '.$currentToken->plainTextToken)
            ->putJson('/api/v1/password', [
                'current_password' => 'old-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
    }

    public function test_changing_password_with_the_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/password', [
            'current_password' => 'wrong-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);
    }

    public function test_a_user_can_delete_their_own_account_with_the_correct_password(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $token = $user->createToken('device')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/profile', ['password' => 'password123'])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_deleting_the_account_with_the_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/profile', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_deleting_the_account_via_the_api_removes_its_storage_directory_from_disk(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create(['password' => 'password123']);
        File::factory()->create(['user_id' => $user->id, 'path' => "{$user->id}/a.txt"]);
        Storage::disk(config('filenest.disk'))->put("{$user->id}/a.txt", 'content');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/profile', ['password' => 'password123'])
            ->assertOk();

        $this->assertFalse(Storage::disk(config('filenest.disk'))->exists("{$user->id}/a.txt"));
    }
}
