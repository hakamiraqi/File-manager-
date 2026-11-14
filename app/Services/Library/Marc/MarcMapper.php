<?php

namespace App\Services\Library\Marc;

use App\Models\Library\Book;
use App\Services\Covers\CoverFinder;

/**
 * Between MARC records and the library's books (2026-11-14). Reads MARC21 and UNIMARC
 * (with Koha's item fields 952 / 995 as copies) and writes MARC21. A book that came from
 * MARC keeps its whole record: on export only what was changed in FileNest is rewritten,
 * so fields FileNest has no place for (authorities, notes, classification) are not lost.
 */
class MarcMapper
{
    /** Separator between authors: a personal name itself holds a comma ("Peterson, Larry J."). */
    public const AUTHORS_GLUE = '; ';

    public const LANGUAGES = [
        'ara' => 'العربية', 'eng' => 'English', 'fre' => 'Français', 'fra' => 'Français', 'ger' => 'Deutsch', 'deu' => 'Deutsch',
        'kur' => 'کوردی', 'per' => 'فارسی', 'fas' => 'فارسی', 'tur' => 'Türkçe', 'spa' => 'Español', 'ita' => 'Italiano', 'rus' => 'Русский',
    ];

    /** Tags each book detail is written to; a detail unchanged since import keeps its original fields. */
    private const ELEMENTS = [
        'title' => ['245'], 'authors' => ['100', '110', '700', '710'], 'isbn' => ['020'], 'edition' => ['250'],
        'publication' => ['260', '264'], 'pages' => ['300'], 'description' => ['520'],
        'subjects' => ['600', '610', '650', '651', '653'], 'language' => ['041'], 'call_number' => ['090'],
    ];

    /**
     * @return array{book: array<string, mixed>, copies: list<array{barcode: ?string, shelf: ?string}>}
     */
    public static function toBook(MarcRecord $r): array
    {
        return $r->isUnimarc() ? self::fromUnimarc($r) : self::fromMarc21($r);
    }

    private static function clean(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        // The punctuation cataloguers put between parts: "Title /", "Elsevier,", "2018."
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        $s = preg_replace('/\s*[\/:;,=،؛]+$/u', '', $s);
        $s = preg_replace('/(?<![A-Z\p{Lu}])\.$/u', '', $s); // a final full stop, not an initial ("J.")
        $s = trim($s, " \t[]");

        return $s === '' ? null : $s;
    }

    private static function year(?string $s): ?string
    {
        return $s && preg_match('/(1[5-9]\d\d|20\d\d)/', $s, $m) ? $m[1] : null;
    }

    private static function isbn(array $values): ?string
    {
        foreach ($values as $v) {
            if ($isbn = CoverFinder::isbns($v)[0] ?? null) {
                return $isbn;
            }
        }
        // A number that fails the checksum is still worth keeping as written.
        foreach ($values as $v) {
            if (preg_match('/[0-9][0-9\-]{8,16}[0-9Xx]/', $v, $m)) {
                return str_replace('-', '', $m[0]);
            }
        }

        return null;
    }

    private static function language(?string $code): ?string
    {
        $code = $code ? strtolower(trim($code)) : null;
        if (! $code || $code === '   ' || $code === 'und' || $code === '|||') {
            return null;
        }

        return self::LANGUAGES[$code] ?? $code;
    }

    private static function pages(?string $s): ?int
    {
        return $s && preg_match('/(\d{1,5})\s*(p\b|pages|pp|ص|صفحة|S\.|Seiten|xii|$)/iu', $s, $m) ? (int) $m[1] : ($s && preg_match('/(\d{2,5})/', $s, $m) ? (int) $m[1] : null);
    }

    private static function fromMarc21(MarcRecord $r): array
    {
        $title = $r->all('245')[0] ?? null;
        $authors = [];
        foreach (['100', '110', '700', '710'] as $tag) {
            foreach ($r->all($tag) as $f) {
                $name = self::clean(MarcRecord::join($f, $tag[1] === '1' ? 'ab' : 'a'));
                if ($name && ! in_array($name, $authors, true)) {
                    $authors[] = $name;
                }
            }
        }
        if (! $authors && $title) {
            $authors = array_filter([self::clean(MarcRecord::join($title, 'c'))]);
        }
        $pub = null;
        foreach ($r->all('264') as $f) {
            if (($f['ind2'] ?? '') === '1') {
                $pub = $f;
            }
        }
        $pub ??= $r->all('264')[0] ?? $r->all('260')[0] ?? null;
        $fixed = (string) $r->control('008');
        $subjects = [];
        foreach (['650', '651', '600', '610', '653'] as $tag) {
            foreach ($r->all($tag) as $f) {
                $s = self::clean(MarcRecord::join($f, $tag === '600' ? 'abcd' : 'a'));
                if ($s && ! in_array($s, $subjects, true)) {
                    $subjects[] = $s;
                }
            }
        }
        $call = $r->sub('090', 'a') ? trim($r->sub('090', 'a').' '.$r->sub('090', 'b'))
            : ($r->sub('082', 'a') ?? ($r->sub('050', 'a') ? trim($r->sub('050', 'a').' '.$r->sub('050', 'b')) : null));

        $copies = [];
        foreach ($r->all('952') as $f) {
            $item = fn ($c) => self::subOf($f, $c);
            $copies[] = ['barcode' => $item('p'), 'shelf' => $item('o') ?? $item('c')];
            $call ??= $item('o');
        }

        return ['book' => [
            'title' => $title ? self::clean(MarcRecord::join($title, 'anp')) : null,
            'subtitle' => $title ? self::clean(MarcRecord::join($title, 'b')) : null,
            'authors' => $authors ? implode(self::AUTHORS_GLUE, $authors) : null,
            'isbn' => self::isbn($r->subs('020', 'a')),
            'publisher' => $pub ? self::clean(self::subOf($pub, 'b')) : null,
            'year' => self::year($pub ? self::subOf($pub, 'c') : null) ?? self::year(substr($fixed, 7, 4)),
            'language' => self::language($r->sub('041', 'a') ?? (strlen($fixed) >= 38 ? substr($fixed, 35, 3) : null)),
            'edition' => self::clean($r->sub('250', 'a')),
            'pages' => self::pages($r->sub('300', 'a')),
            'description' => $r->sub('520', 'a'),
            'subjects' => $subjects ? implode('؛ ', $subjects) : null,
            'call_number' => self::clean($call),
            'marc_control' => self::clean($r->control('001')),
        ], 'copies' => $copies];
    }

    private static function fromUnimarc(MarcRecord $r): array
    {
        $title = $r->all('200')[0] ?? null;
        $authors = [];
        foreach (['700', '701', '702', '710', '711'] as $tag) {
            foreach ($r->all($tag) as $f) {
                $a = self::subOf($f, 'a');
                $b = self::subOf($f, 'b');
                $name = self::clean($a && $b ? "{$a}, {$b}" : $a);
                if ($name && ! in_array($name, $authors, true)) {
                    $authors[] = $name;
                }
            }
        }
        if (! $authors && $title) {
            $authors = array_filter([self::clean(self::subOf($title, 'f'))]);
        }
        $pub = $r->all('210')[0] ?? $r->all('214')[0] ?? null;
        $copies = [];
        foreach ($r->all('995') as $f) {
            $copies[] = ['barcode' => self::subOf($f, 'f'), 'shelf' => self::subOf($f, 'k')];
        }

        return ['book' => [
            'title' => $title ? self::clean(MarcRecord::join($title, 'ahi')) : null,
            'subtitle' => $title ? self::clean(self::subOf($title, 'e')) : null,
            'authors' => $authors ? implode(self::AUTHORS_GLUE, $authors) : null,
            'isbn' => self::isbn($r->subs('010', 'a')),
            'publisher' => $pub ? self::clean(self::subOf($pub, 'c')) : null,
            'year' => self::year($pub ? self::subOf($pub, 'd') : null),
            'language' => self::language($r->sub('101', 'a')),
            'edition' => self::clean($r->sub('205', 'a')),
            'pages' => self::pages($r->sub('215', 'a')),
            'description' => $r->sub('330', 'a'),
            'subjects' => ($s = $r->subs('606', 'a')) ? implode('؛ ', array_map(fn ($x) => self::clean($x), $s)) : null,
            'call_number' => self::clean($r->sub('676', 'a') ?? $r->sub('686', 'a')),
            'marc_control' => self::clean($r->control('001')),
        ], 'copies' => $copies];
    }

    private static function subOf(array $field, string $code): ?string
    {
        foreach ($field['subfields'] ?? [] as [$c, $v]) {
            if ($c === $code && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    /** The MARC21 record for a book, its copies as Koha items (952). */
    public static function fromBook(Book $book, string $itemType = 'BK', ?string $branch = null): MarcRecord
    {
        $stored = $book->marc_xml ? (MarcReader::xmlString($book->marc_xml)[0] ?? null) : null;
        if ($stored && $stored->isUnimarc()) {
            $stored = null; // written fresh as MARC21
        }
        $was = $stored ? self::toBook($stored)['book'] : [];
        $r = $stored ?? new MarcRecord('00000nam a22000007a 4500');
        $r->leader = substr(str_pad($r->leader, 24), 0, 9).'a'.substr(str_pad($r->leader, 24), 10);

        $changed = function (string $element, array $keys) use ($stored, $was, $book) {
            if (! $stored) {
                return true;
            }
            foreach ($keys as $k) {
                if ((string) ($was[$k] ?? '') !== (string) ($book->{$k} ?? '')) {
                    return true;
                }
            }

            return false;
        };

        if (! $r->control('001')) {
            $r->addControl('001', $book->marc_control ?: 'FN'.$book->id);
        }
        $r->remove('005');
        $r->addControl('005', now()->format('YmdHis').'.0');
        if (! $r->control('008')) {
            $lang = array_search($book->language, self::LANGUAGES, true) ?: (strlen((string) $book->language) === 3 ? $book->language : '   ');
            $year = str_pad(substr((string) $book->year, 0, 4), 4, '|');
            $r->addControl('008', substr($book->created_at?->format('ymd') ?? now()->format('ymd'), 0, 6).'s'.$year.'    xx            000 0 '.str_pad((string) $lang, 3).' d');
        }

        $authors = array_values(array_filter(array_map('trim', preg_split('/\s*[;؛]\s*/u', (string) $book->authors))));
        $set = [
            'isbn' => [['isbn'], fn () => $r->addField('020', ' ', ' ', [['a', $book->isbn]])],
            'authors' => [['authors'], function () use ($r, $authors) {
                foreach ($authors as $i => $name) {
                    $r->addField($i === 0 ? '100' : '700', '1', ' ', [['a', $name]]);
                }
            }],
            'title' => [['title', 'subtitle'], fn () => $r->addField('245', $authors ? '1' : '0', '0', [
                ['a', $book->title.($book->subtitle ? ' :' : ($authors ? ' /' : ''))], ['b', $book->subtitle ? $book->subtitle.($authors ? ' /' : '') : null],
                ['c', $authors ? implode(', ', $authors) : null],
            ])],
            'edition' => [['edition'], fn () => $r->addField('250', ' ', ' ', [['a', $book->edition]])],
            'publication' => [['publisher', 'year'], fn () => $r->addField('264', ' ', '1', [['b', $book->publisher.($book->year ? ',' : '')], ['c', $book->year]])],
            'pages' => [['pages'], fn () => $r->addField('300', ' ', ' ', [['a', $book->pages ? $book->pages.' p.' : null]])],
            'description' => [['description'], fn () => $r->addField('520', ' ', ' ', [['a', $book->description]])],
            'subjects' => [['subjects'], function () use ($r, $book) {
                foreach (array_filter(array_map('trim', preg_split('/\s*[;؛]\s*/u', (string) $book->subjects))) as $s) {
                    $r->addField('653', ' ', ' ', [['a', $s]]);
                }
            }],
            'language' => [['language'], function () use ($r, $book) {
                $code = array_search($book->language, self::LANGUAGES, true);
                if ($code) {
                    $r->addField('041', ' ', ' ', [['a', $code]]);
                }
            }],
            'call_number' => [['call_number'], fn () => $r->addField('090', ' ', ' ', [['a', $book->call_number]])],
        ];
        foreach ($set as $element => [$keys, $write]) {
            if ($changed($element, $keys)) {
                $r->remove(...self::ELEMENTS[$element]);
                if ($element === 'call_number' && $stored) {
                    $r->remove('082', '050');
                }
                $write();
            }
        }

        // The copies, always as they are now.
        $r->remove('952', '995');
        foreach ($book->copies as $copy) {
            $r->addField('952', ' ', ' ', [['a', $branch], ['b', $branch], ['o', $book->call_number ?: $copy->shelf], ['p', $copy->barcode], ['y', $itemType]]);
        }
        $r->sort();

        return $r;
    }
}
