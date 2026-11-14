<?php

namespace Tests\Feature\Library;

use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Setting;
use App\Models\User;
use App\Services\Library\Marc\MarcMapper;
use App\Services\Library\Marc\MarcReader;
use App\Services\Library\Marc\MarcRecord;
use App\Services\Library\Marc\MarcWriter;
use App\Services\Library\Marc\SruClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarcTest extends TestCase
{
    use RefreshDatabase;

    /** A Koha-style MARC21 record with two items. */
    private function kohaRecord(string $control = 'KOHA-1', string $title = 'جراحة الفم والفكين', array $barcodes = ['B001', 'B002'], string $isbn = '978-0-323-41492-0'): MarcRecord
    {
        $r = new MarcRecord;
        $r->addControl('001', $control);
        $r->addControl('008', '180101s2018    iq            000 0 ara d');
        $r->addField('020', ' ', ' ', [['a', $isbn.' (hardback)']]);
        $r->addField('100', '1', ' ', [['a', 'أحمد، علي،']]);
        $r->addField('245', '1', '0', [['a', $title.' :'], ['b', 'دليل عملي /'], ['c', 'علي أحمد.']]);
        $r->addField('264', ' ', '1', [['a', 'بغداد :'], ['b', 'دار المعرفة،'], ['c', '2018.']]);
        $r->addField('300', ' ', ' ', [['a', '412 صفحة ;']]);
        $r->addField('500', ' ', ' ', [['a', 'ملاحظة يحتفظ بها السجل الأصلي']]);
        $r->addField('650', ' ', '0', [['a', 'جراحة الفم.']]);
        $r->addField('090', ' ', ' ', [['a', 'RK529'], ['b', '.A4']]);
        foreach ($barcodes as $b) {
            $r->addField('952', ' ', ' ', [['a', 'MAIN'], ['b', 'MAIN'], ['o', 'R-12'], ['p', $b], ['y', 'BK']]);
        }

        return $r;
    }

    private function librarian(): User
    {
        return User::factory()->create(['is_librarian' => true]);
    }

    public function test_iso_and_xml_round_trip_keep_arabic_text(): void
    {
        $record = $this->kohaRecord();

        $iso = MarcWriter::iso($record);
        $this->assertSame(strlen($iso), (int) substr($iso, 0, 5));
        $back = MarcReader::iso($iso);
        $this->assertSame('جراحة الفم والفكين :', $back->sub('245', 'a'));
        $this->assertSame(['B001', 'B002'], $back->subs('952', 'p'));

        $xml = MarcWriter::xml($record, true);
        $fromXml = MarcReader::xmlString($xml)[0];
        $this->assertSame($back->fields, $fromXml->fields);

        $book = MarcMapper::toBook($back)['book'];
        $this->assertSame('جراحة الفم والفكين', $book['title']);
        $this->assertSame('دليل عملي', $book['subtitle']);
        $this->assertSame('أحمد، علي', $book['authors']);
        $this->assertSame('9780323414920', $book['isbn']);
        $this->assertSame('دار المعرفة', $book['publisher']);
        $this->assertSame('2018', $book['year']);
        $this->assertSame('العربية', $book['language']);
        $this->assertSame(412, $book['pages']);
        $this->assertSame('RK529 .A4', $book['call_number']);
        $this->assertSame('KOHA-1', $book['marc_control']);
    }

    public function test_records_from_old_arabic_systems_in_windows_1256_are_read(): void
    {
        $title = iconv('UTF-8', 'CP1256', 'طب الأسنان');
        $r = new MarcRecord;
        $r->addField('245', '0', '0', [['a', $title]]);

        $this->assertSame('طب الأسنان', MarcReader::iso(MarcWriter::iso($r))->sub('245', 'a'));
    }

    public function test_unimarc_records_are_mapped(): void
    {
        $r = new MarcRecord;
        $r->addControl('001', 'U-9');
        $r->addField('010', ' ', ' ', [['a', '9780323414920']]);
        $r->addField('101', '0', ' ', [['a', 'fre']]);
        $r->addField('200', '1', ' ', [['a', 'Chirurgie buccale'], ['e', 'guide pratique'], ['f', 'Jean Dupont']]);
        $r->addField('210', ' ', ' ', [['a', 'Paris'], ['c', 'Masson'], ['d', '2015']]);
        $r->addField('700', ' ', '1', [['a', 'Dupont'], ['b', 'Jean']]);
        $r->addField('995', ' ', ' ', [['f', 'U-COPY-1'], ['k', 'C-4']]);

        ['book' => $book, 'copies' => $copies] = MarcMapper::toBook($r);
        $this->assertSame('Chirurgie buccale', $book['title']);
        $this->assertSame('guide pratique', $book['subtitle']);
        $this->assertSame('Dupont, Jean', $book['authors']);
        $this->assertSame('Masson', $book['publisher']);
        $this->assertSame('2015', $book['year']);
        $this->assertSame([['barcode' => 'U-COPY-1', 'shelf' => 'C-4']], $copies);
    }

    public function test_a_koha_export_is_imported_with_its_items_once(): void
    {
        $librarian = $this->librarian();
        $file = MarcWriter::iso($this->kohaRecord()).MarcWriter::iso($this->kohaRecord('KOHA-2', 'Oral Pathology', ['B003'], '9780132350884'));
        $upload = fn () => UploadedFile::fake()->createWithContent('koha.mrc', $file);

        $this->actingAs($librarian)->post(route('library.manage.import.marc'), ['file' => $upload()])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, Book::count());
        $book = Book::where('marc_control', 'KOHA-1')->firstOrFail();
        $this->assertSame('جراحة الفم والفكين', $book->title);
        $this->assertSame('RK529 .A4', $book->call_number);
        $this->assertSame(['B001', 'B002'], $book->copies()->orderBy('id')->pluck('barcode')->all());
        $this->assertSame('R-12', $book->copies()->first()->shelf);

        // The same file again adds nothing.
        $this->actingAs($librarian)->post(route('library.manage.import.marc'), ['file' => $upload()])->assertRedirect();
        $this->assertSame(2, Book::count());
        $this->assertSame(3, BookCopy::count());

        // "update" refreshes the record from the file and adds only new barcodes.
        $book->update(['title' => 'عنوان قديم']);
        $file2 = MarcWriter::iso($this->kohaRecord('KOHA-1', 'جراحة الفم والفكين', ['B001', 'B009']));
        $this->actingAs($librarian)->postJson(route('library.manage.import.marc'), [
            'file' => UploadedFile::fake()->createWithContent('koha.xml', MarcWriter::xmlOpen().MarcWriter::xml(MarcReader::iso($file2)).MarcWriter::xmlClose()),
            'existing' => 'update',
        ])->assertOk()->assertJson(['added' => 0, 'updated' => 1, 'copies' => 1]);
        $this->assertSame('جراحة الفم والفكين', $book->fresh()->title);
        $this->assertSame(4, BookCopy::count());
    }

    public function test_a_broken_file_is_refused_with_a_message(): void
    {
        $this->actingAs($this->librarian())->post(route('library.manage.import.marc'), [
            'file' => UploadedFile::fake()->createWithContent('bad.mrc', '00099xxxxxxxxxxxxxxxxxxxxnot marc'),
        ])->assertSessionHasErrors('file');
        $this->assertSame(0, Book::count());
    }

    public function test_the_catalogue_exports_as_marc_for_koha(): void
    {
        $librarian = $this->librarian();
        $this->actingAs($librarian)->post(route('library.manage.import.marc'), [
            'file' => UploadedFile::fake()->createWithContent('koha.mrc', MarcWriter::iso($this->kohaRecord())),
        ]);
        $this->actingAs($librarian)->post(route('library.manage.books.store'), [
            'title' => 'Local Book', 'authors' => 'Ali; Sara', 'isbn' => '9780132350884', 'copies' => 1, 'shelf' => 'Z1', 'call_number' => 'QA76',
        ])->assertRedirect();

        $mrc = $this->actingAs($librarian)->get(route('library.manage.export.marc', ['format' => 'mrc', 'item_type' => 'REF', 'branch' => 'DENT']))
            ->assertOk()->streamedContent();
        $records = [];
        $tmp = tempnam(sys_get_temp_dir(), 'marc');
        file_put_contents($tmp, $mrc);
        foreach (MarcReader::file($tmp) as $r) {
            $records[] = $r;
        }
        @unlink($tmp);
        $this->assertCount(2, $records);

        [$imported, $local] = $records;
        // The imported record keeps what FileNest does not model.
        $this->assertSame('ملاحظة يحتفظ بها السجل الأصلي', $imported->sub('500', 'a'));
        $this->assertSame(['B001', 'B002'], $imported->subs('952', 'p'));
        $this->assertSame(['REF', 'REF'], $imported->subs('952', 'y'));
        $this->assertSame('DENT', $imported->sub('952', 'a'));

        $this->assertSame('Local Book', MarcMapper::toBook($local)['book']['title']);
        $this->assertSame(['Ali', 'Sara'], array_map(fn ($v) => rtrim($v, ',. '), [...$local->subs('100'), ...$local->subs('700')]));
        $this->assertSame('9780132350884', $local->sub('020', 'a'));
        $this->assertNotNull($local->sub('952', 'p'));

        $xml = $this->actingAs($librarian)->get(route('library.manage.export.marc', ['format' => 'xml']))->assertOk()->streamedContent();
        $this->assertCount(2, MarcReader::xmlString($xml));
    }

    public function test_copy_cataloguing_searches_the_world_catalogues(): void
    {
        $r = new MarcRecord;
        $r->addControl('001', '123');
        $r->addField('020', ' ', ' ', [['a', '9780132350884']]);
        $r->addField('100', '1', ' ', [['a', 'Martin, Robert C.']]);
        $r->addField('245', '1', '0', [['a', 'Clean code :'], ['b', 'a handbook of agile software craftsmanship /']]);
        $r->addField('952', ' ', ' ', [['p', 'THEIR-SHELF']]);
        $body = '<?xml version="1.0"?><searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/"><numberOfRecords>1</numberOfRecords><records><record><recordData>'
            .MarcWriter::xml($r).'</recordData></record></records></searchRetrieveResponse>';
        $offline = false;
        Http::fake(function ($request) use ($body, &$offline) {
            if (str_contains($request->url(), 'k10plus')) {
                return Http::response('down', 503);
            }

            return $offline ? Http::response('', 500) : Http::response($body, 200, ['Content-Type' => 'application/xml']);
        });

        $res = $this->actingAs($this->librarian())->getJson(route('library.manage.catalog-search', ['isbn' => '978-0-13-235088-4']))->assertOk();
        // LoC and DNB return the same book: it is listed once; K10plus is reported as unreachable.
        $res->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.book.title', 'Clean code')
            ->assertJsonPath('results.0.book.isbn', '9780132350884')
            ->assertJsonPath('errors', ['K10plus (GBV/SWB)']);
        $this->assertStringNotContainsString('THEIR-SHELF', $res->json('results.0.marc_xml'));
        Http::assertSent(fn ($req) => str_contains(urldecode($req->url()), 'bath.isbn="9780132350884"'));

        $this->actingAs($this->librarian())->getJson(route('library.manage.catalog-search'))->assertStatus(422);
    }

    public function test_a_book_saved_from_a_world_record_keeps_it(): void
    {
        $r = $this->kohaRecord('LOC-77');
        $this->actingAs($this->librarian())->post(route('library.manage.books.store'), [
            'title' => 'جراحة الفم والفكين', 'copies' => 1, 'marc_xml' => MarcWriter::xml($r, true),
        ])->assertRedirect();

        $book = Book::firstOrFail();
        $this->assertSame('LOC-77', $book->marc_control);
        $this->assertStringContainsString('ملاحظة يحتفظ بها السجل الأصلي', $book->marc_xml);
        $this->assertArrayNotHasKey('marc_xml', $book->toArray());
    }

    public function test_world_catalogues_are_configurable(): void
    {
        $librarian = $this->librarian();
        $this->actingAs($librarian)->post(route('library.manage.sru.save'), ['servers' => [
            ['name' => 'Bad', 'url' => 'https://x.test/sru', 'isbn' => 'a" or 1', 'title' => 'dc.title', 'author' => 'dc.creator', 'schema' => 'marcxml', 'on' => true],
        ]])->assertSessionHasErrors('servers.0.isbn');

        $this->actingAs($librarian)->post(route('library.manage.sru.save'), ['servers' => [
            ['name' => 'Koha UoB', 'url' => 'https://koha.example.iq/biblios', 'isbn' => 'bath.isbn', 'title' => 'dc.title', 'author' => 'dc.creator', 'schema' => 'marcxml', 'on' => true],
        ]])->assertSessionHasNoErrors();
        $this->assertSame('Koha UoB', SruClient::servers()[0]['name']);
        $this->assertCount(1, SruClient::servers());

        $this->actingAs($librarian)->get(route('library.manage.settings'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('sruServers.0.name', 'Koha UoB')->has('sruDefaults', count(SruClient::DEFAULT_SERVERS)));

        $this->actingAs(User::factory()->create())->post(route('library.manage.sru.save'), ['servers' => []])->assertForbidden();
        $this->assertNotNull(Setting::get('library_sru_servers'));
    }
}
