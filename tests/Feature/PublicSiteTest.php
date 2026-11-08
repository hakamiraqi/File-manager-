<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Library\Book;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitors_browse_the_library_and_see_availability_only_when_it_is_switched_on(): void
    {
        $book = Book::create(['title' => 'Oral Anatomy']);
        $book->copies()->create(['barcode' => 'C1']);
        Book::create(['title' => 'Missing Book']);

        $this->get(route('library.index'))->assertRedirect(route('login'));

        $this->seed(RolePermissionSeeder::class);
        Setting::set('guest_access_enabled', '1');

        $this->get(route('guest.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Guest/Home')->where('stats.books', 2)->has('latestBooks', 2)
                ->where('appSettings.publicSite.library', true));
        $this->get(route('library.index', ['q' => 'Anatomy']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Library/Index')->where('books.total', 1)->where('books.data.0.available_count', 1));
        $this->get(route('library.index', ['available' => 1]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('books.total', 1));
        $this->get(route('library.books.show', $book))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Library/Book')->where('copies.0.status', 'available')->where('myHold', null));

        Setting::set('public_library_enabled', '0');
        $this->get(route('library.index'))->assertRedirect(route('login'));
        $this->get(route('guest.index'))->assertInertia(fn (Assert $page) => $page->where('stats.books', null)->where('appSettings.publicSite.library', false));

        // Signed-in readers keep the catalogue either way.
        $this->actingAs(User::factory()->create())->get(route('library.index'))->assertOk();
    }

    public function test_sections_are_switched_from_the_security_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);

        $this->actingAs($admin)->post(route('admin.security.update'), [
            'guest_access_enabled' => true,
            'public_library_enabled' => true,
            'public_theses_enabled' => false,
            'public_files_enabled' => false,
            'contact_enabled' => true,
            'ip_filter_mode' => 'none',
        ])->assertSessionHasNoErrors();

        $this->assertSame('0', Setting::get('public_theses_enabled'));
        $this->assertSame('0', Setting::get('public_files_enabled'));

        auth()->logout();
        $this->get(route('theses.index'))->assertRedirect(route('login'));
        $this->get(route('guest.files'))->assertNotFound();
        $this->get(route('guest.index'))->assertOk();
    }

    public function test_a_visitor_sends_a_request_and_staff_reply_from_the_inbox(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $librarian = User::factory()->create(['is_librarian' => true]);
        $book = Book::create(['title' => 'Periodontics']);

        $this->get(route('contact.create', ['book' => $book->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Contact/Create')->where('about.title', 'Periodontics')->where('about.kind', 'book_request'));

        // Name and a way to reach the visitor are required.
        $this->post(route('contact.store'), ['kind' => 'book_request', 'subject' => 'x', 'message' => 'y'])
            ->assertSessionHasErrors(['name', 'email']);

        $this->post(route('contact.store'), [
            'kind' => 'book_request', 'name' => 'Sara', 'phone' => '0770', 'subject' => 'Need it',
            'message' => 'Please add more copies', 'about_type' => 'book', 'about_id' => $book->id,
        ])->assertSessionHas('success');

        $msg = ContactMessage::firstOrFail();
        $this->assertSame(['new', 'Sara', 'Periodontics'], [$msg->status, $msg->name, $msg->aboutTitle()]);
        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('kind', 'contact_message')->exists());
        $this->assertTrue(UserNotification::where('user_id', $librarian->id)->where('kind', 'contact_message')->exists());

        // A bot filling the hidden field is dropped quietly.
        $this->post(route('contact.store'), ['kind' => 'other', 'name' => 'b', 'email' => 'b@b.io', 'subject' => 's', 'message' => 'm', 'website' => 'spam'])
            ->assertSessionHas('success');
        $this->assertSame(1, ContactMessage::count());

        // Ordinary users have no inbox; librarians do.
        $this->actingAs(User::factory()->create())->get(route('contact.inbox'))->assertForbidden();
        $this->actingAs($librarian)->get(route('contact.inbox'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Contact/Inbox')->where('counts.new', 1)->where('items.data.0.about_title', 'Periodontics'));

        $this->actingAs($librarian)->patch(route('contact.update', $msg), ['status' => 'closed', 'reply' => 'Ordered two copies'])
            ->assertSessionHas('success');
        $msg->refresh();
        $this->assertSame(['closed', 'Ordered two copies', $librarian->id], [$msg->status, $msg->reply, $msg->handled_by]);
        $this->assertNotNull($msg->replied_at);
    }

    public function test_a_signed_in_user_sees_the_reply_and_contact_can_be_switched_off(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('contact.store'), ['kind' => 'inquiry', 'subject' => 'Hours?', 'message' => 'When do you open?'])
            ->assertSessionHas('success');
        $msg = ContactMessage::firstOrFail();
        $this->assertSame([$user->id, $user->name, $user->email], [$msg->user_id, $msg->name, $msg->email]);

        $msg->update(['reply' => '8 to 2', 'status' => 'closed']);
        $this->actingAs($user)->get(route('contact.create'))
            ->assertInertia(fn (Assert $page) => $page->where('mine.0.reply', '8 to 2'));

        Setting::set('contact_enabled', '0');
        $this->actingAs($user)->get(route('contact.create'))->assertNotFound();
    }
}
