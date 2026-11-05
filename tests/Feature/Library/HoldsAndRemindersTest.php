<?php

namespace Tests\Feature\Library;

use App\Mail\EventNotificationMail;
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\Hold;
use App\Models\Library\Holiday;
use App\Models\Library\LibraryMember;
use App\Models\Library\Loan;
use App\Models\Library\Notice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HoldsAndRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_returned_copy_is_set_aside_for_the_first_reader_in_the_queue(): void
    {
        Mail::fake();
        $librarian = User::factory()->create(['is_librarian' => true]);
        $book = Book::create(['title' => 'Histology']);
        $copy = $book->copies()->create(['barcode' => 'H1']);
        $borrower = LibraryMember::create(['name' => 'a', 'card_number' => 'M1', 'type' => 'student']);
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'H1', 'member_id' => $borrower->id])->assertOk();

        // A reader holds the book while it is out: they wait in the queue.
        $reader = User::factory()->create();
        $this->actingAs($reader)->post(route('library.holds.store', $book))->assertSessionHasNoErrors();
        $hold = Hold::firstOrFail();
        $this->assertSame(Hold::WAITING, $hold->status);

        // The borrower cannot renew while someone waits.
        $loan = Loan::firstOrFail();
        $this->actingAs($librarian)->post(route('library.loans.renew', $loan))->assertSessionHasErrors('code');

        // On return the copy is set aside for the reader, who is told.
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'H1'])
            ->assertOk()->assertJsonPath('held_for.name', $reader->name);
        $this->assertSame(BookCopy::ON_HOLD, $copy->fresh()->status);
        $this->assertSame(Hold::READY, $hold->fresh()->status);
        $this->assertTrue(Notice::where('kind', 'hold_ready')->exists());
        Mail::assertQueued(EventNotificationMail::class);

        // Only that reader can take it.
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'H1', 'member_id' => $borrower->id])->assertStatus(422);
        $member = LibraryMember::where('user_id', $reader->id)->first();
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'H1', 'member_id' => $member->id])->assertOk();
        $this->assertSame(Hold::FULFILLED, $hold->fresh()->status);
    }

    public function test_an_uncollected_hold_expires_and_the_copy_goes_back(): void
    {
        Mail::fake();
        $book = Book::create(['title' => 'x']);
        $copy = $book->copies()->create(['barcode' => 'X1']);
        $reader = User::factory()->create();
        $this->actingAs($reader)->post(route('library.holds.store', $book));
        $this->assertSame(BookCopy::ON_HOLD, $copy->fresh()->status);

        $this->travel(49)->hours();
        $this->artisan('library:expire-holds')->assertSuccessful();
        $this->assertSame(Hold::EXPIRED, Hold::first()->status);
        $this->assertSame(BookCopy::AVAILABLE, $copy->fresh()->status);
    }

    public function test_reminders_and_holidays(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-11-02 06:00:00'); // Monday
        Holiday::create(['date' => '2026-11-16', 'name' => 'عطلة']);
        $member = LibraryMember::create(['name' => 'r', 'card_number' => 'M9', 'type' => 'student', 'email' => 'r@example.com']);
        $copy = Book::create(['title' => 'Physiology'])->copies()->create(['barcode' => 'P1']);
        $librarian = User::factory()->create(['is_librarian' => true]);

        // 14 days lands on the holiday (Mon 16th) so it moves to Tue 17th.
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'P1', 'member_id' => $member->id])->assertOk();
        $loan = Loan::firstOrFail();
        $this->assertSame('2026-11-17', $loan->due_at->toDateString());

        Carbon::setTestNow('2026-11-14 06:00:00'); // 3 days before
        $this->artisan('library:remind')->assertSuccessful();
        $this->assertSame(1, Notice::where('kind', 'due_soon')->count());
        $this->artisan('library:remind'); // not twice a day
        $this->assertSame(1, Notice::where('kind', 'due_soon')->count());

        Carbon::setTestNow('2026-11-19 06:00:00'); // 2 days late
        $this->artisan('library:remind');
        $this->assertSame(1, Notice::where('kind', 'overdue')->count());
        Carbon::setTestNow('2026-11-20 06:00:00');
        $this->artisan('library:remind'); // next late reminder after 3 days
        $this->assertSame(1, Notice::where('kind', 'overdue')->count());
        Carbon::setTestNow('2026-11-22 06:00:00');
        $this->artisan('library:remind');
        $this->assertSame(2, Notice::where('kind', 'overdue')->count());
        Mail::assertQueued(EventNotificationMail::class, fn ($m) => $m->hasTo('r@example.com'));
    }
}
