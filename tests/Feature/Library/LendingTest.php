<?php

namespace Tests\Feature\Library;

use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\LibraryMember;
use App\Models\Library\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LendingTest extends TestCase
{
    use RefreshDatabase;

    private function copy(string $barcode = 'LIB1'): BookCopy
    {
        return Book::create(['title' => 'Anatomy'])->copies()->create(['barcode' => $barcode]);
    }

    public function test_the_counter_lends_to_the_scanned_reader_and_receives_back(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00'); // a Monday
        $librarian = User::factory()->create(['is_librarian' => true]);
        $member = LibraryMember::create(['name' => 'علي', 'card_number' => 'M1', 'type' => 'student']);
        $copy = $this->copy();

        // Scanning the card selects the reader.
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'M1'])
            ->assertOk()->assertJsonPath('kind', 'member')->assertJsonPath('member.id', $member->id);

        // Scanning the book lends it for 14 days (student default).
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'LIB1', 'member_id' => $member->id])
            ->assertOk()->assertJsonPath('kind', 'issued');
        $loan = Loan::firstOrFail();
        $this->assertSame('2026-11-16', $loan->due_at->toDateString());
        $this->assertSame(BookCopy::LOANED, $copy->fresh()->status);

        // Returned 3 days late (Tue, Wed, Thu): 3 x 250.
        Carbon::setTestNow('2026-11-19 09:00:00');
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'LIB1'])
            ->assertOk()->assertJsonPath('kind', 'returned')->assertJsonPath('loan.fine_amount', 750);
        $this->assertSame(BookCopy::AVAILABLE, $copy->fresh()->status);

        // An unpaid fine blocks the next loan until it is paid.
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'LIB1', 'member_id' => $member->id])
            ->assertStatus(422);
        $this->actingAs($librarian)->post(route('library.manage.loans.pay', $loan))->assertRedirect();
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'LIB1', 'member_id' => $member->id])
            ->assertOk()->assertJsonPath('kind', 'issued');
    }

    public function test_limits_and_reference_books_are_enforced(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $member = LibraryMember::create(['name' => 'x', 'card_number' => 'M2', 'type' => 'student']);
        foreach (['A', 'B', 'C', 'D'] as $code) {
            $this->copy($code);
        }

        foreach (['A', 'B', 'C'] as $code) {
            $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => $code, 'member_id' => $member->id])->assertOk();
        }
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'D', 'member_id' => $member->id])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'وصل القارئ للحد الأقصى (3 كتب).');

        $ref = Book::create(['title' => 'Dictionary', 'reference_only' => true])->copies()->create(['barcode' => 'R']);
        $other = LibraryMember::create(['name' => 'y', 'card_number' => 'M3', 'type' => 'teacher']);
        $this->actingAs($librarian)->postJson(route('library.manage.counter.scan'), ['code' => 'R', 'member_id' => $other->id])
            ->assertStatus(422);
    }

    public function test_a_reader_renews_their_own_loan_once(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        $user = User::factory()->create();
        $member = LibraryMember::forUser($user);
        $loan = Loan::create(['copy_id' => $this->copy()->id, 'member_id' => $member->id, 'issued_at' => now(), 'due_at' => now()->addDays(14)->endOfDay()]);

        $this->actingAs($user)->post(route('library.loans.renew', $loan))->assertSessionHasNoErrors();
        $this->assertSame(1, $loan->fresh()->renewals);
        $this->actingAs($user)->post(route('library.loans.renew', $loan))->assertSessionHasErrors('code');

        $this->actingAs($user)->get(route('library.my-loans'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('active.0.id', $loan->id));

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->post(route('library.loans.renew', $loan))->assertForbidden();
    }
}
