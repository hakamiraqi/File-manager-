<?php

namespace Tests\Feature\Api;

use App\Models\File;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrashShareApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_trashed_items_can_be_listed_and_restored_via_the_api(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);
        $file->delete();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/trash')
            ->assertOk()
            ->assertJsonPath('files.0.id', $file->id);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/trash/restore', ['type' => 'file', 'id' => $file->id])
            ->assertOk();

        $this->assertDatabaseHas('files', ['id' => $file->id, 'deleted_at' => null]);
    }

    public function test_a_trashed_file_can_be_permanently_deleted_via_the_api(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id, 'path' => 'a.txt']);
        Storage::disk(config('filenest.disk'))->put('a.txt', 'x');
        $file->delete();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/trash/file/{$file->id}")
            ->assertOk();

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_a_share_link_can_be_created_updated_and_revoked_via_the_api(): void
    {
        $user = User::factory()->create();
        $file = File::factory()->create(['user_id' => $user->id]);

        $createResponse = $this->actingAs($user, 'sanctum')->postJson('/api/v1/shares', [
            'shareable_type' => 'file',
            'shareable_id' => $file->id,
        ]);

        $createResponse->assertCreated();
        $shareId = $createResponse->json('share.id');
        $this->assertNotNull($createResponse->json('share.url'));

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/shares/{$shareId}", ['allow_download' => false])
            ->assertOk()
            ->assertJsonPath('share.allow_download', false);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/shares/{$shareId}")
            ->assertOk();

        $this->assertDatabaseMissing('shares', ['id' => $shareId]);
    }

    public function test_a_user_cannot_share_another_users_file(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $file = File::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder, 'sanctum')->postJson('/api/v1/shares', [
            'shareable_type' => 'file',
            'shareable_id' => $file->id,
        ])->assertNotFound();
    }
}
