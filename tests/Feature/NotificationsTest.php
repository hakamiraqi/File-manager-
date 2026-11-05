<?php

namespace Tests\Feature;

use App\Models\Library\Book;
use App\Models\Library\LibraryMember;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Library\LoanService;
use App\Services\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lending_a_book_notifies_the_reader_and_opening_marks_it_read(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $reader = User::factory()->create();
        $member = LibraryMember::create(['name' => $reader->name, 'card_number' => 'M1', 'type' => 'student', 'user_id' => $reader->id]);
        $copy = Book::create(['title' => 'Anatomy'])->copies()->create(['barcode' => 'B1']);

        app(LoanService::class)->issue($member, $copy, $librarian);

        $n = UserNotification::where('user_id', $reader->id)->firstOrFail();
        $this->assertSame('loan_issued', $n->kind);
        $this->assertStringContainsString('Anatomy', $n->title);

        $this->actingAs($reader)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('notificationsUnread', 1));

        $this->actingAs($reader)->getJson(route('notifications.recent'))
            ->assertOk()->assertJsonPath('unread', 1)->assertJsonPath('items.0.title', $n->title);

        $this->actingAs($reader)->get(route('notifications.open', $n))->assertRedirect(route('library.my-loans', [], false));
        $this->assertNotNull($n->fresh()->read_at);
        $this->assertSame(0, UserNotification::unreadCount($reader));
    }

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

    public function test_assigning_a_folder_and_a_new_hold_notify_the_right_people(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $user = User::factory()->create();
        $folder = \App\Models\Folder::factory()->create(['name' => 'Theses', 'user_id' => $admin->id]);

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'is_active' => true, 'folder_ids' => [$folder->id],
        ]);
        $this->assertTrue(UserNotification::where('user_id', $user->id)->where('kind', 'folder_assigned')->exists());

        $librarian = User::factory()->create(['is_librarian' => true]);
        $reader = User::factory()->create();
        $member = LibraryMember::create(['name' => 'r', 'card_number' => 'M2', 'type' => 'student', 'user_id' => $reader->id]);
        $book = Book::create(['title' => 'Histology']);
        $book->copies()->create(['barcode' => 'H1']);
        app(\App\Services\Library\HoldService::class)->place($member, $book);

        $this->assertTrue(UserNotification::where('user_id', $librarian->id)->where('kind', 'hold_placed')->exists());
        $this->assertFalse(UserNotification::where('user_id', $reader->id)->where('kind', 'hold_placed')->exists());
    }
}
