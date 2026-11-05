<?php

namespace Tests\Feature\Library;

use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\LibrarySection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_librarian_adds_a_book_with_numbered_copies(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $section = LibrarySection::create(['name' => 'جراحة الفم']);

        $this->actingAs($librarian)->post(route('library.manage.books.store'), [
            'title' => 'Oral Surgery', 'authors' => 'Peterson', 'isbn' => '978-0-323-41492-0',
            'section_id' => $section->id, 'copies' => 2, 'shelf' => 'A3',
        ])->assertRedirect();

        $book = Book::firstOrFail();
        $this->assertSame('9780323414920', $book->isbn);
        $this->assertSame(['LIB000001', 'LIB000002'], $book->copies()->orderBy('id')->pluck('barcode')->all());
        $this->assertSame('A3', $book->copies()->first()->shelf);

        // Readers find it by title, author or barcode.
        $reader = User::factory()->create();
        foreach (['Oral', 'Peterson', 'LIB000002'] as $term) {
            $this->actingAs($reader)->get(route('library.index', ['q' => $term]))->assertOk()
                ->assertInertia(fn ($page) => $page->where('books.total', 1)->where('books.data.0.available_count', 2));
        }
    }

    public function test_readers_cannot_manage_the_library(): void
    {
        $reader = User::factory()->create();

        $this->actingAs($reader)->get(route('library.manage.books'))->assertForbidden();
        $this->actingAs($reader)->post(route('library.manage.books.store'), ['title' => 'x', 'copies' => 1])->assertForbidden();
    }

    public function test_books_are_imported_from_csv_with_sections_created_from_their_path(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $csv = "\xEF\xBB\xBFالعنوان,المؤلف,القسم,عدد النسخ,الرف\nكتاب أ,مؤلف,الدراسات الأولية / جراحة الفم,2,B1\nكتاب ب,,الدراسات الأولية,1,\n";

        $this->actingAs($admin)->post(route('library.manage.import'), [
            'file' => UploadedFile::fake()->createWithContent('books.csv', $csv),
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, Book::count());
        $this->assertSame(3, BookCopy::count());
        $child = LibrarySection::where('name', 'جراحة الفم')->firstOrFail();
        $this->assertSame('الدراسات الأولية', $child->parent->name);
        $this->assertSame('الدراسات الأولية / جراحة الفم', Book::where('title', 'كتاب أ')->first()->section->path());
    }

    public function test_a_copy_rfid_tag_cannot_be_used_twice(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $book = Book::create(['title' => 'x']);
        $a = $book->copies()->create(['barcode' => 'A1', 'rfid_tag' => 'E200001']);
        $b = $book->copies()->create(['barcode' => 'A2']);

        $this->actingAs($librarian)->put(route('library.manage.copies.update', $b), ['rfid_tag' => 'E200001'])
            ->assertSessionHasErrors('rfid_tag');
        $this->actingAs($librarian)->put(route('library.manage.copies.update', $b), ['rfid_tag' => 'E200002'])
            ->assertSessionHasNoErrors();
        $this->assertSame($b->id, BookCopy::findByCode('E200002')->id);
    }
}
