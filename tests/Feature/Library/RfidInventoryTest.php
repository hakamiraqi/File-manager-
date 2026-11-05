<?php

namespace Tests\Feature\Library;

use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\LibrarySection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RfidInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tag_is_bound_to_a_copy_and_conflicts_are_reported(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $book = Book::create(['title' => 'Anatomy']);
        $a = $book->copies()->create(['barcode' => 'A1']);
        $b = $book->copies()->create(['barcode' => 'B1']);

        $this->actingAs($librarian)->get(route('library.manage.rfid'))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('library.manage.rfid'))->assertForbidden();

        $this->actingAs($librarian)->postJson(route('library.manage.rfid.link'), ['copy_id' => $a->id, 'tag' => 'E200TAG1'])
            ->assertOk()->assertJsonPath('copy.rfid_tag', 'E200TAG1');

        // The same tag on another copy needs confirmation, then moves.
        $this->actingAs($librarian)->postJson(route('library.manage.rfid.link'), ['copy_id' => $b->id, 'tag' => 'E200TAG1'])
            ->assertStatus(409)->assertJsonPath('conflict', true);
        $this->actingAs($librarian)->postJson(route('library.manage.rfid.link'), ['copy_id' => $b->id, 'tag' => 'E200TAG1', 'move' => true])->assertOk();
        $this->assertNull($a->fresh()->rfid_tag);
        $this->assertSame('E200TAG1', $b->fresh()->rfid_tag);

        // Scanning another copy's barcode switches to that copy instead of binding it as a tag.
        $this->actingAs($librarian)->postJson(route('library.manage.rfid.link'), ['copy_id' => $b->id, 'tag' => 'A1'])
            ->assertStatus(409)->assertJsonPath('switch.id', $a->id);
    }

    public function test_inventory_reports_missing_misplaced_unknown_and_away_copies(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $dental = LibrarySection::create(['name' => 'Dental']);
        $sub = LibrarySection::create(['name' => 'Ortho', 'parent_id' => $dental->id]);
        $other = LibrarySection::create(['name' => 'Medicine']);

        $book = Book::create(['title' => 'Ortho basics', 'section_id' => $sub->id]);
        $onShelf = $book->copies()->create(['barcode' => 'C1', 'rfid_tag' => 'T1']);
        $missing = $book->copies()->create(['barcode' => 'C2']);
        $loaned = $book->copies()->create(['barcode' => 'C3', 'status' => BookCopy::LOANED]);
        $wrong = Book::create(['title' => 'Physiology', 'section_id' => $other->id])->copies()->create(['barcode' => 'M1', 'rfid_tag' => 'T9']);

        $res = $this->actingAs($librarian)->postJson(route('library.manage.rfid.inventory'), [
            'section_id' => $dental->id, 'codes' => ['T1', 'T9', 'ZZZ'],
        ])->assertOk();

        $res->assertJsonPath('found.0.id', $onShelf->id)
            ->assertJsonPath('missing.0.id', $missing->id)
            ->assertJsonPath('misplaced.0.id', $wrong->id)
            ->assertJsonPath('misplaced.0.section_path', 'Medicine')
            ->assertJsonPath('away.0.id', $loaned->id)
            ->assertJsonPath('unknown', ['ZZZ'])
            ->assertJsonPath('expected', 3);

        // Missing copies can be marked lost; a lent one is never touched.
        $this->actingAs($librarian)->postJson(route('library.manage.rfid.mark'), ['copy_ids' => [$missing->id, $loaned->id], 'status' => 'lost'])
            ->assertJsonPath('updated', 1);
        $this->assertSame(BookCopy::LOST, $missing->fresh()->status);
        $this->assertSame(BookCopy::LOANED, $loaned->fresh()->status);
    }
}
