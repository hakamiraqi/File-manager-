<?php

namespace Tests\Feature\Library;

use App\Models\File;
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\Holiday;
use App\Models\Library\LibraryMember;
use App\Models\Library\LibrarySection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** The website's library management and OCR pages, reached by the apps through the API. */
class LibraryAppApiTest extends TestCase
{
    use RefreshDatabase;

    private function librarian(): User
    {
        return User::factory()->create(['is_librarian' => true]);
    }

    public function test_a_reader_cannot_reach_library_management(): void
    {
        $reader = User::factory()->create();

        $this->actingAs($reader, 'sanctum')->getJson('/api/v1/library/manage/books')->assertForbidden();
        $this->actingAs($reader, 'sanctum')->postJson('/api/v1/library/manage/members', ['name' => 'x', 'type' => 'student'])->assertForbidden();
        $this->actingAs($reader, 'sanctum')->getJson('/api/v1/web-link?page=map-editor')->assertForbidden();
    }

    public function test_books_and_copies_are_managed_through_the_api(): void
    {
        $staff = $this->librarian();
        $section = LibrarySection::create(['name' => 'جراحة الفم']);

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/library/manage/books', [
            'title' => 'Oral Surgery', 'authors' => 'Peterson', 'section_id' => $section->id, 'copies' => 2, 'shelf' => 'A1',
        ])->assertOk()->assertJsonPath('message', 'تمت إضافة الكتاب.');

        $book = Book::firstOrFail();
        $this->assertSame(2, $book->copies()->count());

        // The page's props come back as JSON.
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/library/manage/books?q=Oral')
            ->assertOk()
            ->assertJsonPath('books.data.0.title', 'Oral Surgery')
            ->assertJsonCount(2, 'books.data.0.copies')
            ->assertJsonStructure(['books' => ['data', 'current_page', 'last_page'], 'sections', 'statuses', 'shelfCodes']);

        // Validation errors are a 422 with the errors.
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/library/manage/books', ['title' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('title');

        $this->actingAs($staff, 'sanctum')->putJson("/api/v1/library/manage/books/{$book->id}", ['title' => 'Oral Surgery 3rd'])->assertOk();
        $this->assertSame('Oral Surgery 3rd', $book->fresh()->title);

        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/library/manage/books/{$book->id}/copies", ['count' => 1])->assertOk();
        $copy = $book->copies()->first();
        $this->actingAs($staff, 'sanctum')->putJson("/api/v1/library/manage/copies/{$copy->id}", ['status' => BookCopy::DAMAGED])->assertOk();
        $this->assertSame(BookCopy::DAMAGED, $copy->fresh()->status);
        $this->actingAs($staff, 'sanctum')->deleteJson("/api/v1/library/manage/copies/{$copy->id}")->assertOk();
        $this->assertSame(2, $book->copies()->count());

        // A lent copy keeps the book: the page's own 422 message comes through.
        $book->copies()->first()->update(['status' => BookCopy::LOANED]);
        $this->actingAs($staff, 'sanctum')->deleteJson("/api/v1/library/manage/books/{$book->id}")
            ->assertStatus(422)->assertJsonPath('message', 'لا يمكن حذف كتاب له نسخ مُعارة حالياً.');
    }

    public function test_sections_members_rules_holidays_and_reports(): void
    {
        $staff = $this->librarian();

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/library/manage/sections', ['name' => 'التشريح'])->assertOk();
        $parent = LibrarySection::firstOrFail();
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/library/manage/sections', ['name' => 'الرأس والرقبة', 'parent_id' => $parent->id])->assertOk();
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/library/manage/sections')
            ->assertOk()->assertJsonCount(2, 'sections')->assertJsonPath('sections.1.path', 'التشريح / الرأس والرقبة');
        // A section cannot go inside itself: the page's "back with errors" becomes a 422.
        $this->actingAs($staff, 'sanctum')->putJson("/api/v1/library/manage/sections/{$parent->id}", ['name' => 'التشريح', 'parent_id' => $parent->id])
            ->assertStatus(422)->assertJsonPath('message', 'لا يمكن وضع القسم داخل نفسه.');

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/library/manage/members', ['name' => 'سارة علي', 'type' => 'student', 'stage' => '3'])->assertOk();
        $member = LibraryMember::firstOrFail();
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/library/manage/members?q=سارة')->assertOk()->assertJsonPath('members.data.0.name', 'سارة علي');
        $this->actingAs($staff, 'sanctum')->putJson("/api/v1/library/manage/members/{$member->id}", ['name' => 'سارة علي حسن', 'type' => 'teacher'])->assertOk();
        $this->assertSame('teacher', $member->fresh()->type);
        $this->actingAs($staff, 'sanctum')->deleteJson("/api/v1/library/manage/members/{$member->id}")->assertOk();

        $settings = $this->actingAs($staff, 'sanctum')->getJson('/api/v1/library/manage/settings')->assertOk()->json();
        $rules = $settings['rules'];
        $rules['types']['student']['loan_days'] = 21;
        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/library/manage/settings', $rules)->assertOk();
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/library/manage/settings')->assertJsonPath('rules.types.student.loan_days', 21);

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/library/manage/holidays', ['from' => now()->addDay()->toDateString(), 'to' => now()->addDays(2)->toDateString(), 'name' => 'عطلة'])->assertOk();
        $this->assertSame(2, Holiday::count());
        $this->actingAs($staff, 'sanctum')->deleteJson('/api/v1/library/manage/holidays/'.Holiday::first()->id)->assertOk();

        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/library/manage/reports')
            ->assertOk()->assertJsonStructure(['totals' => ['loans', 'books', 'members'], 'monthly', 'topBooks', 'filters']);
    }

    public function test_the_ocr_page_for_admins_who_manage_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $owner = User::factory()->create();
        $file = File::factory()->create(['user_id' => $owner->id, 'ocr_status' => 'failed', 'ocr_attempts' => 3]);

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/admin/ocr')->assertForbidden();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/ocr?status=failed')
            ->assertOk()
            ->assertJsonPath('counts.failed', 1)
            ->assertJsonPath('files.data.0.id', $file->id)
            ->assertJsonStructure(['settings', 'engine', 'state', 'chart', 'week', 'filters']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/ocr/retry-failed')->assertOk()->assertJsonPath('message', 'أُعيد 1 ملف إلى قائمة الانتظار.');
        $this->assertSame('pending', $file->fresh()->ocr_status);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/ocr/files/{$file->id}/skip")->assertOk();
        $this->assertSame('skipped', $file->fresh()->ocr_status);
    }

    public function test_the_signed_link_opens_a_staff_page_signed_in_without_the_menus(): void
    {
        $staff = $this->librarian();

        $link = $this->actingAs($staff, 'sanctum')->getJson('/api/v1/web-link?page=map-editor')
            ->assertOk()->assertJsonPath('external', false)->json('path');
        $this->assertStringStartsWith('/app/open?', $link);

        $this->app['auth']->shouldUse('web');
        $this->get($link)->assertRedirect(route('library.manage.map'));
        $this->assertAuthenticatedAs($staff);
        $this->get(route('library.manage.map'))->assertOk()->assertInertia(fn ($page) => $page->where('inApp', true));

        // Printing pages open in the phone's browser and keep the site's menus there.
        $cards = $this->actingAs($staff, 'sanctum')->getJson('/api/v1/web-link?page=cards&ids[]=4&ids[]=5')
            ->assertOk()->assertJsonPath('external', true)->json('path');
        $this->assertStringContainsString('ids', urldecode($cards));

        // A tampered or expired link does nothing.
        $this->get($link.'x')->assertForbidden();
        $expired = URL::temporarySignedRoute('app.open', now()->subMinute(), ['user' => $staff->id, 'page' => 'rfid'], absolute: false);
        $this->get($expired)->assertForbidden();
    }
}
