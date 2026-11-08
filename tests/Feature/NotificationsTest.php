<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_only_see_and_open_their_own_notifications(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        Notify::to($a, 'files', 'folder_assigned', 'Folder X');
        $n = UserNotification::firstOrFail();

        $this->actingAs($b)->get(route('notifications.open', $n))->assertNotFound();
        $this->actingAs($b)->get(route('notifications.index'))
            ->assertInertia(fn (Assert $page) => $page->component('Notifications/Index')->where('counts.all', 0));
        $this->actingAs($a)->get(route('notifications.index', ['filter' => 'unread']))
            ->assertInertia(fn (Assert $page) => $page->where('counts.unread', 1)->has('items.data', 1));

        $this->actingAs($a)->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, UserNotification::unreadCount($a));
    }
}
