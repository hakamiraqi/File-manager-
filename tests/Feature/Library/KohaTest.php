<?php

namespace Tests\Feature\Library;

use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\Hold;
use App\Models\Library\LibraryMember;
use App\Models\Library\Loan;
use App\Models\Setting;
use App\Models\User;
use App\Services\Library\Koha\KohaSettings;
use App\Services\Library\Koha\KohaSync;
use App\Services\Library\Marc\MarcRecord;
use App\Services\Library\Marc\MarcWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** «الخيار ب»: Koha lends, FileNest is the front. Koha is faked at its API. */
class KohaTest extends TestCase
{
    use RefreshDatabase;

    private const KOHA = 'https://koha.test';

    /** What the fake Koha holds; tests change it between calls. */
    private array $koha = [];

    /** @var list<string> "METHOD path" of every call Koha received */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setMany([
            'koha_enabled' => '1', 'koha_url' => self::KOHA, 'koha_client_id' => 'fn', 'koha_library' => 'DENT',
            'koha_client_secret' => Crypt::encryptString('s3cret'),
        ]);
        $this->koha = [
            'patrons' => [7 => ['patron_id' => 7, 'cardnumber' => 'M1', 'firstname' => 'علي', 'surname' => 'حسن', 'email' => 'ali@uni.iq', 'category_id' => 'ST']],
            'items' => [55 => ['item_id' => 55, 'biblio_id' => 9, 'external_id' => 'LIB1', 'location' => 'A-3', 'callnumber' => 'RK51']],
            'checkouts' => [],
            'holds' => [],
            'blockers' => [],
        ];
        Http::fake(fn (Request $r) => $this->answer($r));
    }

    private function answer(Request $r)
    {
        $path = substr(parse_url($r->url(), PHP_URL_PATH), strlen('/api/v1'));
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
        $q = isset($query['q']) ? json_decode($query['q'], true) : [];
        $this->calls[] = $r->method().' '.$path;

        return match (true) {
            $path === '/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            $r->method() === 'GET' && $path === '/patrons' => Http::response(array_values(array_filter($this->koha['patrons'], fn ($p) => $p['cardnumber'] === ($q['cardnumber'] ?? null)))),
            $r->method() === 'GET' && preg_match('#^/patrons/(\d+)$#', $path, $m) === 1 => Http::response($this->koha['patrons'][$m[1]] ?? ['error' => 'Not found'], isset($this->koha['patrons'][$m[1]]) ? 200 : 404),
            $r->method() === 'GET' && $path === '/items' => Http::response(array_values(isset($q['external_id'])
                ? array_filter($this->koha['items'], fn ($i) => $i['external_id'] === $q['external_id'])
                : (($query['_page'] ?? 1) == 1 ? $this->koha['items'] : []))),
            $r->method() === 'GET' && preg_match('#^/items/(\d+)$#', $path, $m) === 1 => Http::response($this->koha['items'][$m[1]]),
            $r->method() === 'GET' && $path === '/biblios' => Http::response(($query['_page'] ?? 1) == 1 ? $this->collection() : '<collection xmlns="http://www.loc.gov/MARC21/slim"/>'),
            $r->method() === 'GET' && preg_match('#^/biblios/(\d+)$#', $path) === 1 => Http::response($this->collection()),
            $path === '/checkouts/availability' => Http::response(['blockers' => (object) $this->koha['blockers'], 'confirms' => (object) [], 'warnings' => (object) [], 'confirmation_token' => null]),
            $r->method() === 'POST' && $path === '/checkouts' => Http::response($this->koha['checkouts'][100] = ['checkout_id' => 100, 'item_id' => $r['item_id'], 'patron_id' => $r['patron_id'], 'due_date' => '2026-12-01T23:59:00+03:00', 'renewals_count' => 0], 201),
            $r->method() === 'GET' && $path === '/checkouts' => Http::response(array_values(array_filter($this->koha['checkouts'],
                fn ($c) => (! isset($query['patron_id']) || $c['patron_id'] == $query['patron_id']) && (! isset($q['item_id']) || $c['item_id'] == $q['item_id'])))),
            $path === '/checkouts/100/allows_renewal' => Http::response(['allows_renewal' => true]),
            $path === '/checkouts/100/renewal' => Http::response(['due_date' => '2026-12-15T23:59:00+03:00'], 201),
            $r->method() === 'GET' && $path === '/checkouts/100' => Http::response(['checkout_id' => 100, 'due_date' => '2026-12-15T23:59:00+03:00', 'renewals_count' => 1]),
            $r->method() === 'POST' && $path === '/checkins' => (function () {
                $this->koha['checkouts'] = [];

                return Http::response(['checkin_id' => 1]);
            })(),
            $r->method() === 'POST' && $path === '/holds' => Http::response(['hold_id' => 300, 'status' => null, 'biblio_id' => $r['biblio_id'], 'patron_id' => $r['patron_id']], 201),
            $r->method() === 'GET' && $path === '/holds' => Http::response(array_values($this->koha['holds'])),
            $r->method() === 'DELETE' && $path === '/holds/300' => Http::response(null, 204),
            $r->method() === 'POST' && $path === '/biblios' => Http::response(['id' => 77]),
            $r->method() === 'POST' && $path === '/biblios/77/items' => Http::response(['item_id' => 88, 'biblio_id' => 77, 'external_id' => $r['external_id']], 201),
            in_array($path, ['/deleted/items', '/deleted/biblios'], true) => Http::response([]),
            default => Http::response(['error' => "no fake for {$r->method()} {$path}"], 500),
        };
    }

    private function collection(): string
    {
        $r = new MarcRecord;
        $r->addControl('001', 'KOHA9');
        $r->addField('020', ' ', ' ', [['a', '9780323414920']]);
        $r->addField('245', '1', '0', [['a', 'Oral Surgery /'], ['c', 'Peterson']]);
        $r->addField('999', ' ', ' ', [['c', '9'], ['d', '9']]);

        return MarcWriter::xmlOpen().MarcWriter::xml($r).MarcWriter::xmlClose();
    }

    private function librarian(): User
    {
        return User::factory()->create(['is_librarian' => true]);
    }

    public function test_the_desk_lends_and_receives_through_koha(): void
    {
        $member = LibraryMember::create(['name' => 'علي', 'card_number' => 'M1', 'type' => 'student']);
        $copy = Book::create(['title' => 'Oral Surgery', 'koha_biblio_id' => 9])->copies()->create(['barcode' => 'LIB1', 'koha_item_id' => 55]);
        $this->calls = [];

        $this->actingAs($this->librarian())->postJson(route('library.manage.counter.scan'), ['code' => 'LIB1', 'member_id' => $member->id])
            ->assertOk()->assertJsonPath('kind', 'issued');
        $loan = Loan::firstOrFail();
        $this->assertSame(100, $loan->koha_checkout_id);
        $this->assertSame('2026-12-01', $loan->due_at->timezone('Asia/Baghdad')->toDateString()); // Koha's date, not FileNest's rules
        $this->assertSame(7, $member->fresh()->koha_patron_id);
        $this->assertSame(BookCopy::LOANED, $copy->fresh()->status);
        $this->assertContains('POST /checkouts', $this->calls);

        // Renewing asks Koha, which sets the new date.
        $this->actingAs($this->librarian())->post(route('library.loans.renew', $loan))->assertRedirect();
        $this->assertSame('2026-12-15', $loan->fresh()->due_at->timezone('Asia/Baghdad')->toDateString());
        $this->assertSame(1, $loan->fresh()->renewals);

        // The return is a Koha check-in; Koha keeps the fine.
        $this->actingAs($this->librarian())->postJson(route('library.manage.counter.scan'), ['code' => 'LIB1'])
            ->assertOk()->assertJsonPath('kind', 'returned')->assertJsonPath('loan.fine_amount', 0);
        $this->assertContains('POST /checkins', $this->calls);
        $this->assertSame(BookCopy::AVAILABLE, $copy->fresh()->status);
    }

    public function test_koha_refusals_reach_the_desk_in_arabic(): void
    {
        $member = LibraryMember::create(['name' => 'علي', 'card_number' => 'M1', 'type' => 'student']);
        Book::create(['title' => 'Oral Surgery', 'koha_biblio_id' => 9])->copies()->create(['barcode' => 'LIB1', 'koha_item_id' => 55]);
        $this->koha['blockers'] = ['DEBT' => 12000];

        $this->actingAs($this->librarian())->postJson(route('library.manage.counter.scan'), ['code' => 'LIB1', 'member_id' => $member->id])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'رفض Koha الإعارة: على القارئ ديون في Koha');
        $this->assertSame(0, Loan::count());

        $stranger = LibraryMember::create(['name' => 'زائر', 'card_number' => 'X9', 'type' => 'student']);
        $this->actingAs($this->librarian())->postJson(route('library.manage.counter.scan'), ['code' => 'LIB1', 'member_id' => $stranger->id])
            ->assertStatus(422)->assertJsonPath('errors.code.0', 'القارئ «زائر» غير مسجل في Koha (رقم البطاقة X9). سجّله في Koha أولاً.');
    }

    public function test_a_reader_holds_and_cancels_in_koha(): void
    {
        $user = User::factory()->create();
        LibraryMember::create(['user_id' => $user->id, 'name' => 'علي', 'card_number' => 'M1', 'type' => 'student']);
        $book = Book::create(['title' => 'Oral Surgery', 'koha_biblio_id' => 9]);
        $book->copies()->create(['barcode' => 'LIB1', 'koha_item_id' => 55]);

        $this->actingAs($user)->post(route('library.holds.store', $book))->assertRedirect();
        $hold = Hold::firstOrFail();
        $this->assertSame(300, $hold->koha_hold_id);
        $this->assertSame(Hold::WAITING, $hold->status);

        $this->actingAs($user)->delete(route('library.holds.destroy', $hold))->assertRedirect();
        $this->assertSame(Hold::CANCELLED, $hold->fresh()->status);
        $this->assertContains('DELETE /holds/300', $this->calls);
    }

    public function test_the_sync_mirrors_koha_catalogue_loans_and_readers(): void
    {
        $this->koha['checkouts'][200] = ['checkout_id' => 200, 'item_id' => 55, 'patron_id' => 8, 'checkout_date' => '2026-11-10T10:00:00+03:00', 'due_date' => '2026-11-24T23:59:00+03:00', 'renewals_count' => 0];
        $this->koha['patrons'][8] = ['patron_id' => 8, 'cardnumber' => 'S100', 'firstname' => 'سارة', 'surname' => 'كريم', 'email' => 'sara@uni.iq', 'category_id' => 'T'];
        $sara = User::factory()->create(['email' => 'sara@uni.iq']);

        app(KohaSync::class)->run();

        $book = Book::where('koha_biblio_id', 9)->firstOrFail();
        $this->assertSame('Oral Surgery', $book->title);
        $copy = BookCopy::where('koha_item_id', 55)->firstOrFail();
        $this->assertSame(['LIB1', 'A-3', BookCopy::LOANED], [$copy->barcode, $copy->shelf, $copy->status]);
        $member = LibraryMember::where('koha_patron_id', 8)->firstOrFail();
        $this->assertSame(['S100', 'teacher', $sara->id], [$member->card_number, $member->type, $member->user_id]);
        $this->assertSame(200, Loan::firstOrFail()->koha_checkout_id);

        // Returned at Koha's own desk: the next sync closes the mirror.
        $this->koha['checkouts'] = [];
        app(KohaSync::class)->run();
        $this->assertNotNull(Loan::firstOrFail()->returned_at);
        $this->assertSame(BookCopy::AVAILABLE, $copy->fresh()->status);
        $this->assertSame(1, Book::count());
    }

    public function test_a_book_catalogued_here_is_sent_to_koha(): void
    {
        $this->actingAs($this->librarian())->post(route('library.manage.books.store'), [
            'title' => 'Local Book', 'authors' => 'Ali', 'copies' => 1, 'shelf' => 'Z1',
        ])->assertRedirect();

        $book = Book::firstOrFail();
        $this->assertSame(77, $book->koha_biblio_id);
        $this->assertSame(88, $book->copies()->first()->koha_item_id);
        $this->assertContains('POST /biblios', $this->calls);
        $this->assertContains('POST /biblios/77/items', $this->calls);
    }

    public function test_a_staff_username_and_password_work_when_koha_allows_basic_auth(): void
    {
        Setting::setMany(['koha_auth' => 'basic', 'koha_username' => 'dent_lib', 'koha_password' => Crypt::encryptString('pa55')]);
        $member = LibraryMember::create(['name' => 'علي', 'card_number' => 'M1', 'type' => 'student']);
        Book::create(['title' => 'Oral Surgery', 'koha_biblio_id' => 9])->copies()->create(['barcode' => 'LIB1', 'koha_item_id' => 55]);
        $this->calls = [];

        $this->actingAs($this->librarian())->postJson(route('library.manage.counter.scan'), ['code' => 'LIB1', 'member_id' => $member->id])
            ->assertOk()->assertJsonPath('kind', 'issued');
        $this->assertNotContains('POST /oauth/token', $this->calls);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/checkouts') && $r->hasHeader('Authorization', 'Basic '.base64_encode('dent_lib:pa55')));
    }

    public function test_only_admins_set_the_key_and_it_never_reaches_the_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->librarian())->post(route('library.manage.koha.save'), ['koha_enabled' => false])->assertForbidden();

        $this->actingAs($admin)->post(route('library.manage.koha.save'), [
            'koha_enabled' => true, 'koha_auth' => 'oauth', 'koha_url' => 'https://koha.uni.iq/', 'koha_client_id' => 'id2', 'koha_client_secret' => 'new-secret',
            'koha_library' => 'CEN', 'koha_match' => 'email', 'koha_create_patrons' => true,
            'koha_categories' => ['student' => 'UG', 'teacher' => 'FAC', 'staff' => 'STF'], 'koha_item_type' => 'BOOK', 'koha_push' => false,
        ])->assertSessionHasNoErrors();
        $this->assertSame('new-secret', KohaSettings::secret());
        $this->assertSame('https://koha.uni.iq', KohaSettings::url());

        $this->actingAs($admin)->get(route('library.manage.settings'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('koha.has_secret', true)->where('koha.koha_library', 'CEN')->missing('koha.koha_client_secret'));
        $this->assertStringNotContainsString('new-secret', $this->actingAs($admin)->get(route('library.manage.settings'))->getContent());

        // Username and password instead of a key: the password is kept encrypted too.
        $this->actingAs($admin)->post(route('library.manage.koha.save'), [
            'koha_enabled' => true, 'koha_auth' => 'basic', 'koha_url' => 'https://koha.uni.iq', 'koha_username' => 'dent_lib', 'koha_password' => 'pa55',
            'koha_match' => 'cardnumber', 'koha_create_patrons' => false,
            'koha_categories' => ['student' => 'ST', 'teacher' => 'T', 'staff' => 'S'], 'koha_push' => true,
        ])->assertSessionHasNoErrors();
        $this->assertTrue(KohaSettings::basic());
        $this->assertSame('pa55', KohaSettings::password());
        $this->assertNotSame('pa55', Setting::get('koha_password'));
        $this->assertStringNotContainsString('pa55', $this->actingAs($admin)->get(route('library.manage.settings'))->getContent());
    }
}
