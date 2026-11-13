<?php

namespace Tests\Feature\Covers;

use App\Models\File;
use App\Models\Folder;
use App\Models\Library\Book;
use App\Models\Setting;
use App\Models\User;
use App\Services\Covers\LibraryCovers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LibraryBookCoversTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filenest.disk'));
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        Setting::set('covers_min_pages', '0');
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(300, 450);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
        imagestring($im, 5, 20, 20, uniqid(), imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    private function pdf(string $name, array $extra = []): File
    {
        $folder = Folder::factory()->create(['user_id' => $this->admin->id]);
        $f = File::factory()->create($extra + [
            'user_id' => $this->admin->id, 'folder_id' => $folder->id, 'name' => $name, 'extension' => 'pdf',
            'mime_type' => 'application/pdf', 'path' => $this->admin->id.'/'.uniqid().'.pdf', 'size' => 10,
            'thumbnail_path' => $this->admin->id.'/thumbnails/'.uniqid().'.jpg', 'cover_status' => 'none', 'cover_checked_at' => now(),
        ]);
        $f->disk()->put($f->path, '%PDF');
        $f->disk()->put($f->thumbnail_path, $this->jpeg());

        return $f->fresh();
    }

    public function test_titles_and_isbn_forms_match(): void
    {
        $this->assertGreaterThanOrEqual(0.85, LibraryCovers::sameBook('20 common problems in Gastroenterology', '20.common.problems.in.gastroenterology'));
        $this->assertGreaterThanOrEqual(0.85, LibraryCovers::sameBook('Oral and Maxillofacial Surgery', 'oral and maxillofacial surgery 3rd ed 2017 Fonseca'));
        $this->assertLessThan(0.85, LibraryCovers::sameBook('Oral Surgery', 'Oral and Maxillofacial Surgery'));
        $this->assertSame(['0632045434', '9780632045433'], LibraryCovers::bothForms('0632045434'));
        $this->assertSame(['9780632045433', '0632045434'], LibraryCovers::bothForms('9780632045433'));
    }

    public function test_shelf_books_take_the_pdf_picture_offline_then_search_online(): void
    {
        $this->pdf('20.common.problems.in.gastroenterology.pdf');
        $this->pdf('UCV - BEHAVIORAL SCIENCE.pdf', ['cover_isbn' => '0632045434', 'cover_source' => 'openlibrary', 'cover_status' => 'found']);
        $byTitle = Book::create(['title' => '20 common problems in Gastroenterology', 'authors' => 'Steven A Edmundowicz']);
        $byIsbn = Book::create(['title' => 'Behavioral science', 'isbn' => '9780632045433']);
        $online = Book::create(['title' => 'Mosby\'s Drug Consult 2002', 'authors' => 'Darrel R']);

        // No internet: the two in the drive still get their cover, the third waits.
        $offline = true;
        $cover = $this->jpeg();
        Http::fake(function ($request) use (&$offline, $cover) {
            if ($offline) {
                throw new ConnectionException('Could not resolve host');
            }
            if (str_contains($request->url(), 'openlibrary.org/search.json')) {
                return Http::response(['docs' => [['title' => "Mosby's Drug Consult 2002", 'cover_i' => 77, 'author_name' => ['Darrel R']]]]);
            }
            if (str_contains($request->url(), 'covers.openlibrary.org/')) {
                return Http::response($cover);
            }

            return Http::response([], 404);
        });
        $this->artisan('covers:run', ['--force' => true])->assertSuccessful();
        $this->assertSame('drive', $byTitle->fresh()->cover_source);
        $this->assertNotNull($byTitle->fresh()->cover_url);
        $this->assertSame('drive', $byIsbn->fresh()->cover_source);
        $this->assertNull($online->fresh()->cover_status);

        // With internet: found by title (and author) on Open Library.
        $offline = false;
        $this->artisan('covers:run', ['--force' => true])->assertSuccessful();
        $this->assertSame('found', $online->fresh()->cover_status);
        $this->assertTrue(Storage::disk('local')->exists($online->fresh()->cover_path));
        $last = json_decode((string) Setting::get('covers_last_run'), true);
        $this->assertSame(1, $last['books_found']);
    }

    public function test_a_book_linked_to_a_pdf_gets_its_picture_when_saved_and_a_removed_cover_stays_removed(): void
    {
        $file = $this->pdf('anything.pdf');
        $this->actingAs($this->admin);
        $book = Book::create(['title' => 'Linked book']);
        $this->put(route('library.manage.books.update', $book), ['title' => 'Linked book', 'file_id' => $file->id])->assertRedirect();
        $this->assertSame('drive', $book->fresh()->cover_source);

        $this->put(route('library.manage.books.update', $book), ['title' => 'Linked book', 'file_id' => $file->id, 'remove_cover' => true])->assertRedirect();
        $this->assertNull($book->fresh()->cover_path);
        $this->assertSame('removed', $book->fresh()->cover_status);
        $this->artisan('covers:run', ['--force' => true])->assertSuccessful();
        $this->assertNull($book->fresh()->cover_path);
    }
}
