<?php

namespace Tests\Feature\Library;

use App\Models\Library\Book;
use App\Models\Library\LibraryMember;
use App\Models\Library\LibrarySection;
use App\Models\Library\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_report_counts_the_period_and_exports_it(): void
    {
        Carbon::setTestNow('2026-11-05 12:00:00');
        $librarian = User::factory()->create(['is_librarian' => true]);
        $dental = LibrarySection::create(['name' => 'Dental']);
        $ortho = LibrarySection::create(['name' => 'Ortho', 'parent_id' => $dental->id]);
        $popular = Book::create(['title' => 'Ortho basics', 'section_id' => $ortho->id]);
        $other = Book::create(['title' => 'Physiology']);
        $c1 = $popular->copies()->create(['barcode' => 'P1']);
        $c2 = $popular->copies()->create(['barcode' => 'P2']);
        $c3 = $other->copies()->create(['barcode' => 'O1']);
        $ali = LibraryMember::create(['name' => 'علي', 'card_number' => 'M1', 'type' => 'student']);
        $sara = LibraryMember::create(['name' => 'سارة', 'card_number' => 'M2', 'type' => 'teacher']);

        $loan = fn ($copy, $member, $issued, $returned = null, $fine = 0, $paid = null) => Loan::create([
            'copy_id' => $copy->id, 'member_id' => $member->id, 'issued_at' => $issued, 'due_at' => Carbon::parse($issued)->addDays(14),
            'returned_at' => $returned, 'fine_amount' => $fine, 'fine_paid_at' => $paid,
        ]);
        $loan($c1, $ali, '2026-09-10 10:00', '2026-09-30 10:00', 1000, '2026-09-30 10:05'); // late, fine paid
        $loan($c2, $sara, '2026-10-03 10:00', '2026-10-10 10:00');
        $loan($c1, $sara, '2026-10-20 10:00');                                               // overdue now
        $loan($c3, $ali, '2025-01-01 10:00', '2025-01-05 10:00');                            // outside the period

        $this->actingAs($librarian)->get(route('library.manage.reports', ['from' => '2026-09-01', 'to' => '2026-11-05']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Library/Manage/Reports')
                ->where('totals.loans', 3)
                ->where('totals.returns', 2)
                ->where('totals.readers', 2)
                ->where('totals.overdue_now', 1)
                ->where('totals.fines_collected', 1000)
                ->where('lateReturns', 1)
                ->where('topBooks.0.title', 'Ortho basics')
                ->where('topBooks.0.count', 3)
                ->where('bySection.0.name', 'Dental')
                ->where('monthly', fn ($m) => collect($m)->pluck('loans', 'month')->all() === ['2026-09' => 1, '2026-10' => 2, '2026-11' => 0]));

        $csv = $this->actingAs($librarian)->get(route('library.manage.reports.export', ['from' => '2026-09-01', 'to' => '2026-11-05']))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('Ortho basics', $csv);
        $this->assertStringNotContainsString('Physiology', $csv);
        $this->assertSame(4, substr_count(trim($csv), "\n") + 1); // header + 3 loans

        $this->actingAs(User::factory()->create())->get(route('library.manage.reports'))->assertForbidden();
    }
}
