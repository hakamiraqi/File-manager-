<?php

namespace Tests\Feature\Library;

use App\Models\Library\Book;
use App\Models\User;
use App\Services\Library\Citation;
use App\Services\Library\DoiLookup;
use App\Services\Library\Marc\MarcMapper;
use App\Services\Library\Marc\MarcReader;
use App\Services\Library\Marc\MarcWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DoiTest extends TestCase
{
    use RefreshDatabase;

    private const DOI = '10.1016/j.joms.2018.01.001';

    private function article(array $extra = []): Book
    {
        return Book::create($extra + [
            'title' => 'Contemporary oral surgery', 'authors' => 'Peterson, Larry J.; Hupp, James R.', 'year' => '2018',
            'journal' => 'Journal of Oral and Maxillofacial Surgery', 'volume' => '76', 'issue' => '3', 'page_range' => '45-60',
            'publisher' => 'Elsevier', 'doi' => self::DOI,
        ]);
    }

    private function librarian(): User
    {
        return User::factory()->create(['is_librarian' => true]);
    }

    public function test_a_doi_is_found_in_whatever_was_pasted(): void
    {
        foreach (['10.1016/J.JOMS.2018.01.001', 'doi:10.1016/j.joms.2018.01.001', 'https://doi.org/10.1016/j.joms.2018.01.001', 'https://dx.doi.org/10.1016%2Fj.joms.2018.01.001.', ' see (10.1016/j.joms.2018.01.001) '] as $input) {
            $this->assertSame(self::DOI, DoiLookup::normalize($input), $input);
        }
        $this->assertNull(DoiLookup::normalize('9780323414920'));
    }

    public function test_the_form_is_filled_from_the_doi_record(): void
    {
        Http::fake(['doi.org/*' => function (Request $r) {
            $this->assertSame('application/vnd.citationstyles.csl+json', $r->header('Accept')[0]);

            return Http::response([
                'type' => 'article-journal', 'title' => 'Contemporary oral surgery',
                'author' => [['family' => 'Peterson', 'given' => 'Larry J.'], ['family' => 'Hupp', 'given' => 'James R.']],
                'container-title' => 'Journal of Oral and Maxillofacial Surgery', 'publisher' => 'Elsevier BV',
                'issued' => ['date-parts' => [[2018, 3]]], 'volume' => '76', 'issue' => '3', 'page' => '45–60', 'language' => 'en',
                'abstract' => '<jats:p>Abstract</jats:p><jats:p>Third molars are <jats:italic>often</jats:italic> removed.</jats:p>',
            ]);
        }]);
        $existing = $this->article(['title' => 'Already here']);

        $this->actingAs($this->librarian())->getJson(route('library.manage.doi', ['doi' => 'https://doi.org/10.1016/J.JOMS.2018.01.001']))
            ->assertOk()
            ->assertJson([
                'doi' => self::DOI, 'title' => 'Contemporary oral surgery', 'authors' => 'Peterson, Larry J.; Hupp, James R.',
                'journal' => 'Journal of Oral and Maxillofacial Surgery', 'year' => '2018', 'volume' => '76', 'issue' => '3',
                'page_range' => '45-60', 'language' => 'English', 'type' => 'article', 'existing' => ['id' => $existing->id],
            ])
            ->assertJsonPath('description', 'Third molars are often removed.');

        $this->actingAs($this->librarian())->getJson(route('library.manage.doi', ['doi' => 'not a doi']))->assertStatus(422);
    }

    public function test_an_unknown_doi_says_so(): void
    {
        Http::fake(['doi.org/*' => Http::response('DOI Not Found', 404)]);
        $this->actingAs($this->librarian())->getJson(route('library.manage.doi', ['doi' => '10.9999/none']))
            ->assertNotFound()->assertJsonPath('message', 'لم يُعثر على هذا الـ DOI (أو لا يوجد إنترنت).');
    }

    public function test_a_saved_doi_is_normalized_and_found_by_search(): void
    {
        $this->actingAs($this->librarian())->post(route('library.manage.books.store'), [
            'title' => 'Contemporary oral surgery', 'doi' => 'https://doi.org/10.1016/J.JOMS.2018.01.001', 'journal' => 'J Oral Maxillofac Surg', 'copies' => 0,
        ])->assertRedirect();
        $this->assertSame(self::DOI, Book::firstOrFail()->doi);

        $this->assertSame(1, Book::search('doi:10.1016/j.joms.2018.01.001')->count());
        $this->assertSame(1, Book::search('Maxillofac')->count());
    }

    public function test_an_article_is_cited_in_apa_and_vancouver(): void
    {
        $c = Citation::for($this->article());

        $this->assertSame('Peterson, L. J., & Hupp, J. R. (2018). Contemporary oral surgery. Journal of Oral and Maxillofacial Surgery, 76(3), 45-60. https://doi.org/10.1016/j.joms.2018.01.001', $c->apa()['text']);
        $this->assertStringContainsString('<i>Journal of Oral and Maxillofacial Surgery, 76</i>(3)', $c->apa()['html']);
        $this->assertSame('Peterson LJ, Hupp JR. Contemporary oral surgery. Journal of Oral and Maxillofacial Surgery. 2018;76(3):45-60. doi:10.1016/j.joms.2018.01.001', $c->vancouver()['text']);

        $bib = $c->bibtex();
        $this->assertStringStartsWith('@article{peterson2018,', $bib);
        $this->assertStringContainsString('  author = {Peterson, Larry J. and Hupp, James R.}', $bib);
        $this->assertStringContainsString('  title = {{Contemporary oral surgery}}', $bib);
        $this->assertStringContainsString('  pages = {45--60}', $bib);

        $ris = $c->ris();
        $this->assertStringStartsWith("TY  - JOUR\r\nAU  - Peterson, Larry J.\r\nAU  - Hupp, James R.\r\n", $ris);
        $this->assertStringContainsString("SP  - 45\r\nEP  - 60\r\n", $ris);
        $this->assertStringContainsString("DO  - 10.1016/j.joms.2018.01.001\r\n", $ris);
        $this->assertStringEndsWith("ER  - \r\n", $ris);
    }

    public function test_books_are_cited_too_with_arabic_names_as_written(): void
    {
        $c = Citation::for(Book::create(['title' => 'جراحة الفم والفكين', 'subtitle' => 'دليل عملي', 'authors' => 'علي حسن؛ سارة كريم', 'year' => '2020', 'publisher' => 'دار المعرفة', 'edition' => '2']));
        $this->assertSame('علي حسن, & سارة كريم (2020). جراحة الفم والفكين: دليل عملي (2nd ed). دار المعرفة.', $c->apa()['text']);
        $this->assertStringStartsWith("TY  - BOOK\r\nAU  - علي حسن\r\n", $c->ris());

        $en = Citation::for(Book::create(['title' => 'Oral Pathology', 'authors' => 'Joseph A. Regezi', 'year' => '2016', 'publisher' => 'Elsevier', 'edition' => '7']));
        $this->assertSame('Regezi, J. A. (2016). Oral Pathology (7th ed). Elsevier.', $en->apa()['text']);
        $this->assertSame('Regezi JA. Oral Pathology. 7th ed. Elsevier; 2016.', $en->vancouver()['text']);
    }

    public function test_the_cite_window_and_files_follow_the_public_catalogue(): void
    {
        $book = $this->article();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('library.books.cite', $book))->assertOk()
            ->assertJsonPath('doi_url', 'https://doi.org/'.self::DOI)
            ->assertJsonStructure(['apa' => ['text', 'html'], 'vancouver' => ['text', 'html'], 'bibtex', 'ris']);
        $this->actingAs($user)->get(route('library.books.cite', ['book' => $book, 'download' => 'ris']))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="contemporary-oral-surgery.ris"');
        $this->actingAs($user)->get(route('library.books.cite', ['book' => $book, 'download' => 'bib']))->assertOk()
            ->assertHeader('Content-Type', 'application/x-bibtex; charset=utf-8');
        $this->actingAs($user)->get(route('library.books.cite', ['book' => $book, 'download' => 'exe']))->assertNotFound();

        // The app reads the same.
        $this->getJson(route('api.v1.public.library.show', $book))->assertJsonPath('book.doi', self::DOI)->assertJsonPath('book.journal', 'Journal of Oral and Maxillofacial Surgery');
    }

    public function test_the_doi_and_journal_travel_in_marc(): void
    {
        $r = MarcMapper::fromBook($this->article());
        $this->assertSame([self::DOI, 'doi'], [$r->sub('024', 'a'), $r->sub('024', '2')]);
        $this->assertSame('https://doi.org/'.self::DOI, $r->sub('856', 'u'));
        $this->assertSame(['Journal of Oral and Maxillofacial Surgery', 'volume 76, issue 3 (2018), pages 45-60'], [$r->sub('773', 't'), $r->sub('773', 'g')]);
        $this->assertSame('pages 45-60', $r->sub('300', 'a'));

        $back = MarcMapper::toBook(MarcReader::iso(MarcWriter::iso($r)))['book'];
        $this->assertSame([self::DOI, 'Journal of Oral and Maxillofacial Surgery', '76', '3', '45-60'],
            [$back['doi'], $back['journal'], $back['volume'], $back['issue'], $back['page_range']]);
    }
}
