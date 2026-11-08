<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_uploads_replaces_and_removes_their_picture(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.avatar'), ['avatar' => UploadedFile::fake()->image('me.png', 900, 600)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $first = $user->fresh()->avatar_path;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);
        $size = getimagesizefromstring(Storage::disk('public')->get($first));
        $this->assertSame([512, 512], [$size[0], $size[1]]);
        $this->assertStringEndsWith($first, $user->fresh()->avatar_url);

        $this->actingAs($user)->post(route('profile.avatar'), ['avatar' => UploadedFile::fake()->image('me2.jpg', 300, 300)]);
        $second = $user->fresh()->avatar_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);

        $this->actingAs($user)->delete(route('profile.avatar.destroy'))->assertRedirect();
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertNull($user->fresh()->avatar_url);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_only_images_are_accepted_and_the_profile_page_shows_numbers(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.avatar'), ['avatar' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar_path);

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile/Edit')
                ->where('stats.attempts', 0)
                ->has('stats.exams')
                ->where('auth.user.avatar_url', null));
    }
}
