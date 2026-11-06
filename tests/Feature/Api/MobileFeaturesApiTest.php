<?php

namespace Tests\Feature\Api;

use App\Models\ContactMessage;
use App\Models\Folder;
use App\Models\Library\Book;
use App\Models\Library\LibraryMember;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Library\LoanService;
use App\Services\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileFeaturesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_library_is_open_to_visitors_only_when_switched_on_and_readers_can_hold(): void
    {
        $book = Book::create(['title' => 'Endodontics']);
        $book->copies()->create(['barcode' => 'E1']);

        $this->getJson('/api/v1/public/library')->assertUnauthorized();
        Setting::set('guest_access_enabled', '1');
        $this->getJson('/api/v1/public/library?q=Endo')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.available_count', 1);
        $this->getJson("/api/v1/public/library/books/{$book->id}")->assertOk()
            ->assertJsonPath('can_hold', false)->assertJsonPath('copies.0.status', 'available');
        $this->getJson('/api/v1/public/site')->assertOk()->assertJsonPath('library', true);

        $reader = User::factory()->create();
        Sanctum::actingAs($reader);
        $this->postJson("/api/v1/library/books/{$book->id}/hold")->assertOk()->assertJsonPath('hold.status', 'ready');
        $this->getJson('/api/v1/library/mine')->assertOk()->assertJsonPath('holds.0.book.title', 'Endodontics');
        $this->getJson('/api/v1/library/manage/loans')->assertForbidden();
    }

    public function test_staff_lend_from_the_app_and_the_reader_sees_and_renews_it(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $reader = User::factory()->create();
        $member = LibraryMember::create(['name' => $reader->name, 'card_number' => 'R1', 'type' => 'student', 'user_id' => $reader->id]);
        Book::create(['title' => 'Prostho'])->copies()->create(['barcode' => 'P9']);

        Sanctum::actingAs($librarian);
        $this->postJson('/api/v1/library/manage/counter/scan', ['code' => 'P9', 'member_id' => $member->id])->assertOk()->assertJsonPath('kind', 'issued');
        $this->getJson('/api/v1/library/manage/loans')->assertOk()->assertJsonPath('counts.active', 1)->assertJsonPath('data.0.book.title', 'Prostho');
        $this->getJson('/api/v1/library/manage/stats')->assertOk()->assertJsonPath('active_loans', 1);

        Sanctum::actingAs($reader);
        $loan = $this->getJson('/api/v1/library/mine')->assertOk()->json('active.0');
        $this->assertSame('Prostho', $loan['book']['title']);
        $this->getJson('/api/v1/me/badges')->assertOk()->assertJsonPath('notifications', 0); // opening «استعاراتي» read them
        $this->postJson("/api/v1/library/loans/{$loan['id']}/renew")->assertOk();
    }

    public function test_notifications_saved_and_theses(): void
    {
        $user = User::factory()->create();
        Notify::to($user, 'files', 'folder_assigned', 'New folder', null, '/dashboard?folder=1');
        Sanctum::actingAs($user);

        $id = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 1)
            ->assertJsonPath('data.0.url', '/dashboard?folder=1')->json('data.0.id');
        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk();
        $this->assertSame(0, UserNotification::unreadCount($user));

        $folder = Folder::factory()->create(['user_id' => $user->id]);
        $this->postJson('/api/v1/saved/toggle', ['type' => 'folder', 'id' => $folder->id])->assertOk()->assertJsonPath('saved', true);
        $this->getJson('/api/v1/saved')->assertOk()->assertJsonPath('saved.0.item.id', $folder->id)->assertJsonPath('ids.folders.0', $folder->id);

        Folder::factory()->create(['user_id' => $user->id, 'folder_type' => 'thesis', 'thesis_title' => 'Caries study', 'degree' => 'master']);
        $this->getJson('/api/v1/public/theses?q=Caries')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.title', 'Caries study');
    }

    public function test_contact_from_the_app_and_the_inbox(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $user = User::factory()->create();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/public/contact', ['kind' => 'suggestion', 'subject' => 'Dark mode', 'message' => 'Please'])->assertCreated();
        $this->getJson('/api/v1/public/contact')->assertOk()->assertJsonPath('mine.0.subject', 'Dark mode');
        $this->getJson('/api/v1/inbox')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/me/badges')->assertOk()->assertJsonPath('contact_new', 1);
        $msg = ContactMessage::firstOrFail();
        $this->getJson('/api/v1/inbox')->assertOk()->assertJsonPath('counts.new', 1)->assertJsonPath('data.0.id', $msg->id);
        $this->patchJson("/api/v1/inbox/{$msg->id}", ['status' => 'closed', 'reply' => 'Done'])->assertOk();
        $this->assertTrue(UserNotification::where('user_id', $user->id)->where('kind', 'contact_reply')->exists());
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.manage_contact', true);
    }
}
