<?php

namespace Tests\Feature\Library;

use App\Models\Library\Book;
use App\Models\Library\LibrarySection;
use App\Models\Library\LibraryShelf;
use App\Models\User;
use App\Services\Library\LibraryMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LibraryMapTest extends TestCase
{
    use RefreshDatabase;

    private function layout(array $shelves): array
    {
        return [
            'floor' => ['width' => 20, 'depth' => 14, 'name' => '', 'entrance' => ['x' => 10, 'y' => 14]],
            'shelves' => array_map(fn ($s) => $s + [
                'kind' => 'shelf', 'name' => null, 'section_id' => null, 'x' => 5, 'y' => 5, 'rotation' => 0,
                'width' => 2, 'depth' => 0.5, 'levels' => 5, 'double_sided' => false, 'color' => null,
            ], $shelves),
        ];
    }

    public function test_a_librarian_saves_the_floor_plan_and_codes_must_be_unique(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $section = LibrarySection::create(['name' => 'جراحة الفم']);

        $this->actingAs($librarian)->put(route('library.manage.map.update'), $this->layout([
            ['code' => 'A1', 'section_id' => $section->id],
            ['code' => 'A2', 'x' => 7, 'double_sided' => true, 'depth' => 0.9],
            ['code' => 'مكتب1', 'kind' => 'desk'],
            ['code' => 'بوابة1', 'kind' => 'gate', 'width' => 1.4, 'levels' => 1],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(4, LibraryShelf::count());
        $this->assertSame('gate', LibraryShelf::where('code', 'بوابة1')->value('kind'));
        $this->assertSame(14.0, app(LibraryMap::class)->floor()['entrance']['y']);

        // Saving again updates by id and removes what was taken off the plan.
        $a1 = LibraryShelf::where('code', 'A1')->first();
        $this->actingAs($librarian)->put(route('library.manage.map.update'), $this->layout([
            ['id' => $a1->id, 'code' => 'A1', 'x' => 3, 'rotation' => 90],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(1, LibraryShelf::count());
        $this->assertSame(90, $a1->fresh()->rotation);

        $this->actingAs($librarian)->put(route('library.manage.map.update'), $this->layout([
            ['code' => 'B1'], ['code' => 'b1'],
        ]))->assertSessionHasErrors('shelves.1.code');

        // An obstacle keeps the outline it was drawn with, and cannot be saved without one.
        $this->actingAs($librarian)->put(route('library.manage.map.update'), $this->layout([
            ['code' => 'عائق1', 'kind' => 'obstacle', 'height' => 2.5, 'points' => [[-1, -0.5], [1, -0.5], [1, 0.5], [0, 0.5], [-1, 0.2]]],
        ]))->assertSessionHasNoErrors();
        $obstacle = LibraryShelf::where('code', 'عائق1')->first();
        $this->assertEquals([[-1, -0.5], [1, -0.5], [1, 0.5], [0, 0.5], [-1, 0.2]], $obstacle->points);
        $this->assertSame(2.5, $obstacle->height);
        $this->assertSame($obstacle->points, app(LibraryMap::class)->shelves()->firstWhere('code', 'عائق1')['points']);

        $this->actingAs($librarian)->put(route('library.manage.map.update'), $this->layout([
            ['code' => 'عائق2', 'kind' => 'obstacle', 'points' => [[0, 0], [1, 1]]],
        ]))->assertSessionHasErrors('shelves.0.points');

        // Several halls and rooms in any direction, joined by doors; bookcases at any angle.
        $this->actingAs($librarian)->put(route('library.manage.map.update'), $this->layout([
            ['code' => 'القاعة الرئيسية', 'kind' => 'hall', 'x' => 6, 'y' => 7, 'width' => 40, 'depth' => 12, 'rotation' => -30, 'points' => [[-20, -6], [20, -6], [20, 6], [-20, 6]]],
            ['code' => 'باب1', 'kind' => 'door', 'width' => 1.2, 'depth' => 0.2, 'rotation' => 405 - 360],
            ['code' => 'C1', 'rotation' => 330],
        ]))->assertSessionHasNoErrors();
        $hall = LibraryShelf::where('kind', 'hall')->first();
        $this->assertSame(330, $hall->rotation);
        $this->assertSame(40.0, $hall->width);
        $this->assertCount(4, $hall->points);
        $this->assertSame(45, LibraryShelf::where('kind', 'door')->value('rotation'));
        $this->assertNull(LibraryShelf::where('kind', 'door')->value('points'));

        $this->actingAs($librarian)->put(route('library.manage.map.update'), $this->layout([
            ['code' => 'غرفة', 'kind' => 'hall'],
        ]))->assertSessionHasErrors('shelves.0.points');

        // A building of two floors: corridors and stairs on each, every hall shown on its own.
        $two = $this->layout([
            ['code' => 'ممر1', 'kind' => 'corridor', 'width' => 20, 'depth' => 3, 'points' => [[-10, -1.5], [10, -1.5], [10, 1.5], [-10, 1.5]]],
            ['code' => 'درج1', 'kind' => 'stairs', 'width' => 2, 'depth' => 3],
            ['code' => 'درج2', 'kind' => 'stairs', 'width' => 2, 'depth' => 3, 'storey' => 1],
            ['code' => 'D1', 'storey' => 1],
        ]);
        $two['floor'] += ['storeys' => ['الأرضي', 'الأول'], 'separate_halls' => true];
        $this->actingAs($librarian)->put(route('library.manage.map.update'), $two)->assertSessionHasNoErrors();
        $this->assertSame(['الأرضي', 'الأول'], app(LibraryMap::class)->floor()['storeys']);
        $this->assertTrue(app(LibraryMap::class)->floor()['separate_halls']);
        $this->assertSame(1, LibraryShelf::where('code', 'D1')->value('storey'));
        $this->assertSame(1, app(LibraryMap::class)->shelves()->firstWhere('code', 'درج2')['storey']);
        $this->assertCount(4, LibraryShelf::where('code', 'ممر1')->value('points'));

        $this->actingAs($librarian)->put(route('library.manage.map.update'), $this->layout([
            ['id' => $a1->id, 'code' => 'A1', 'x' => 3, 'rotation' => 90],
        ]))->assertSessionHasNoErrors();

        $this->actingAs($librarian)->get(route('library.manage.map'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Library/Manage/Map')->has('shelves', 1));
    }

    public function test_catalogue_search_results_show_each_books_bookcase(): void
    {
        LibraryShelf::create(['code' => 'A3', 'x' => 5, 'y' => 5, 'width' => 2, 'depth' => 0.5, 'levels' => 5]);
        $book = Book::create(['title' => 'Oral Surgery']);
        $book->copies()->create(['barcode' => 'X1', 'shelf' => 'A3-2']);
        Book::create(['title' => 'Oral Medicine']);

        $this->actingAs(User::factory()->create())->get(route('library.index', ['q' => 'Oral']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Library/Index')
                ->where('books.data.0.title', 'Oral Medicine')->where('books.data.0.map_spot', null)
                ->where('books.data.1.map_spot', ['code' => 'A3', 'level' => 2])
                ->missing('books.data.1.copies'));
    }

    public function test_a_locked_item_keeps_its_lock(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $this->actingAs($librarian)->put(route('library.manage.map.update'), $this->layout([
            ['code' => 'A1', 'locked' => true], ['code' => 'A2', 'x' => 8],
        ]))->assertSessionHasNoErrors();

        $this->assertTrue(LibraryShelf::where('code', 'A1')->value('locked'));
        $this->assertFalse(LibraryShelf::where('code', 'A2')->value('locked'));
        $this->assertTrue(app(LibraryMap::class)->shelves()->firstWhere('code', 'A1')['locked']);
    }

    public function test_readers_cannot_edit_the_map(): void
    {
        $reader = User::factory()->create();
        $this->actingAs($reader)->get(route('library.manage.map'))->assertForbidden();
        $this->actingAs($reader)->put(route('library.manage.map.update'), $this->layout([]))->assertForbidden();
    }

    public function test_a_book_is_found_by_its_copy_shelf_code_or_its_section(): void
    {
        $section = LibrarySection::create(['name' => 'تقويم']);
        $child = LibrarySection::create(['name' => 'تقويم متحرك', 'parent_id' => $section->id]);
        $a3 = LibraryShelf::create(['code' => 'A3', 'x' => 5, 'y' => 5, 'width' => 2, 'depth' => 0.5, 'levels' => 5]);
        $c1 = LibraryShelf::create(['code' => 'C1', 'section_id' => $section->id, 'x' => 9, 'y' => 5, 'width' => 2, 'depth' => 0.5, 'levels' => 5]);

        $byCode = Book::create(['title' => 'Oral Surgery']);
        $byCode->copies()->create(['barcode' => 'X1', 'shelf' => 'a3-2']);
        $byCode->copies()->create(['barcode' => 'X2', 'shelf' => 'A3/2', 'status' => 'loaned']);

        $bySection = Book::create(['title' => 'Removable Ortho', 'section_id' => $child->id]);
        $bySection->copies()->create(['barcode' => 'Y1', 'shelf' => 'رف قديم']);

        $map = app(LibraryMap::class);
        $spots = $map->locate($byCode);
        $this->assertCount(1, $spots);
        $this->assertSame([$a3->id, 2, 1, 2, false], [$spots[0]['shelf_id'], $spots[0]['level'], $spots[0]['available'], $spots[0]['total'], $spots[0]['by_section']]);

        $spots = $map->locate($bySection);
        $this->assertSame([$c1->id, null, true], [$spots[0]['shelf_id'], $spots[0]['level'], $spots[0]['by_section']]);

        // A level past the top of the bookcase is ignored, not invented.
        $this->assertNull($map->parseShelf('A3-9')[1]);
        $this->assertNull($map->parseShelf('Z9'));
    }

    public function test_the_map_and_its_search_follow_the_public_library_switch(): void
    {
        $shelf = LibraryShelf::create(['code' => 'B2', 'x' => 5, 'y' => 5, 'width' => 2, 'depth' => 0.5, 'levels' => 5]);
        $book = Book::create(['title' => 'Dental Anatomy']);
        $book->copies()->create(['barcode' => 'Z1', 'shelf' => 'B2']);

        $this->get(route('library.map'))->assertRedirect(route('login'));
        $this->getJson(route('library.map.search', ['q' => 'Anatomy']))->assertForbidden();

        $reader = User::factory()->create();
        $this->actingAs($reader)->get(route('library.map', ['book' => $book->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Library/Map')
                ->has('shelves', 1)->where('target.spots.0.code', 'B2')->where('canEdit', false));
        $this->actingAs($reader)->getJson(route('library.map.search', ['q' => 'Anatomy']))->assertOk()
            ->assertJsonPath('books.0.spots.0.shelf_id', $shelf->id)
            ->assertJsonPath('books.0.available_count', 1);

        // The book page links to the map.
        $this->actingAs($reader)->get(route('library.books.show', $book))
            ->assertInertia(fn (Assert $page) => $page->where('spots.0.code', 'B2'));
    }

    public function test_the_apps_open_the_map_signed_in_as_the_same_user(): void
    {
        $shelf = LibraryShelf::create(['code' => 'B2', 'x' => 5, 'y' => 5, 'width' => 2, 'depth' => 0.5, 'levels' => 5]);
        $book = Book::create(['title' => 'Dental Anatomy']);
        $book->copies()->create(['barcode' => 'Z1', 'shelf' => 'B2-3']);

        // Visitors need the public library switched on.
        $this->getJson('/api/v1/public/library/map-link')->assertUnauthorized();

        $reader = User::factory()->create();
        $token = $reader->createToken('phone')->plainTextToken;
        $this->withToken($token)->getJson("/api/v1/public/library/books/{$book->id}")->assertOk()
            ->assertJsonPath('map_spot.code', 'B2')->assertJsonPath('map_spot.level', 3);
        $path = $this->withToken($token)->getJson("/api/v1/public/library/map-link?book={$book->id}")->assertOk()->json('path');
        $this->assertStringStartsWith('/library/map/app?', $path);

        // The app's web view follows the link: signed in, then the map alone with the book.
        $this->app['auth']->forgetGuards();
        $this->get($path)->assertRedirect(route('library.map', ['book' => $book->id, 'app' => 1]));
        $this->assertAuthenticatedAs($reader);
        $this->get(route('library.map', ['book' => $book->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Library/Map')->where('embedded', true)->where('target.spots.0.shelf_id', $shelf->id));

        // A changed link does not sign anyone in.
        auth()->logout();
        $this->get(str_replace('user='.$reader->id, 'user='.($reader->id + 1), $path))->assertForbidden();
        $this->assertGuest();
    }

    public function test_qr_stickers_open_the_map_at_their_bookcase(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $shelf = LibraryShelf::create(['code' => 'A3', 'x' => 5, 'y' => 5, 'width' => 2, 'depth' => 0.5, 'levels' => 5]);
        LibraryShelf::create(['code' => 'مكتب1', 'kind' => 'desk', 'x' => 9, 'y' => 9, 'width' => 2, 'depth' => 0.8, 'levels' => 1]);

        $this->actingAs($librarian)->get(route('library.manage.map.labels'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Library/Manage/MapLabels')
                ->has('shelves', 1)->where('shelves.0.url', route('library.map', ['at' => $shelf->id])));
        $this->actingAs(User::factory()->create())->get(route('library.manage.map.labels'))->assertForbidden();

        $this->actingAs(User::factory()->create())->get(route('library.map', ['at' => $shelf->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('startAt', $shelf->id));
    }

    public function test_wall_stickers_are_saved_and_printed_after_the_bookcases(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $this->actingAs($librarian)->put(route('library.manage.map.update'), $this->layout([
            ['code' => 'QR1', 'name' => 'بجانب باب 1', 'kind' => 'qr', 'x' => 3, 'y' => 0.06, 'rotation' => 0, 'width' => 0.3, 'depth' => 0.1, 'levels' => 1],
            ['code' => 'A1', 'kind' => 'shelf', 'x' => 5, 'y' => 5, 'rotation' => 0, 'width' => 2, 'depth' => 0.5, 'levels' => 5],
        ]))->assertSessionHasNoErrors();
        $sticker = LibraryShelf::where('kind', 'qr')->firstOrFail();

        $this->get(route('library.manage.map.labels'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('shelves', 2)
                ->where('shelves.0.code', 'A1')
                ->where('shelves.1.kind', 'qr')->where('shelves.1.name', 'بجانب باب 1')
                ->where('shelves.1.url', route('library.map', ['at' => $sticker->id])));
    }

    public function test_a_building_plan_picture_is_kept_under_its_floor_and_old_ones_are_removed(): void
    {
        Storage::fake('public');
        $librarian = User::factory()->create(['is_librarian' => true]);
        $this->actingAs($librarian)->postJson(route('library.manage.map.background'), ['plan' => UploadedFile::fake()->create('notes.txt', 3)])->assertStatus(422);
        $up = $this->postJson(route('library.manage.map.background'), ['plan' => UploadedFile::fake()->image('plan.png', 800, 400)])
            ->assertOk()->assertJsonPath('aspect', 0.5)->json();
        Storage::disk('public')->assertExists($up['path']);
        $old = $this->postJson(route('library.manage.map.background'), ['plan' => UploadedFile::fake()->image('old.png', 100, 100)])->json('path');

        $layout = $this->layout([['code' => 'A1']]);
        $layout['floor']['backgrounds'] = [['storey' => 0, 'path' => $up['path'], 'x' => 0, 'y' => 0, 'width' => 40, 'aspect' => 0.5, 'opacity' => 0.6, 'hidden' => false]];
        $this->put(route('library.manage.map.update'), $layout)->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($old);
        $floor = app(LibraryMap::class)->floor();
        $this->assertSame($up['path'], $floor['backgrounds'][0]['path']);
        $this->assertEquals(40, $floor['backgrounds'][0]['width']);

        // A path outside the plans folder is never kept.
        $layout['floor']['backgrounds'][0]['path'] = 'avatars/someone.jpg';
        $this->put(route('library.manage.map.update'), $layout)->assertSessionHasNoErrors();
        $this->assertSame([], app(LibraryMap::class)->floor()['backgrounds']);
        $this->actingAs(User::factory()->create())->postJson(route('library.manage.map.background'), [])->assertForbidden();
    }

    public function test_the_map_check_counts_books_with_no_place(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $section = LibrarySection::create(['name' => 'جراحة']);
        LibraryShelf::create(['code' => 'A1', 'x' => 5, 'y' => 5, 'width' => 2, 'depth' => 0.5, 'levels' => 5, 'section_id' => $section->id]);
        Book::create(['title' => 'Placed', 'section_id' => $section->id]);
        Book::create(['title' => 'Lost']);

        $this->actingAs($librarian)->getJson(route('library.manage.map.unplaced'))->assertOk()
            ->assertJsonPath('count', 1)->assertJsonPath('books.0.title', 'Lost');
    }
}
