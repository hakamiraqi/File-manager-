<?php

namespace App\Services\Library;

use App\Models\Library\Book;
use Illuminate\Support\Str;

/**
 * A book or article as a reference: APA 7 and Vancouver (the style of medical and dental
 * journals), and as BibTeX and RIS files for EndNote, Zotero and Mendeley.
 */
class Citation
{
    public function __construct(private Book $b) {}

    public static function for(Book $book): self
    {
        return new self($book);
    }

    public function isArticle(): bool
    {
        return filled($this->b->journal);
    }

    /** @return list<array{family: string, given: string, whole: bool}> */
    private function authors(): array
    {
        return collect(preg_split('/\s*[;؛]\s*/u', (string) $this->b->authors))->map('trim')->filter()->map(function ($n) {
            $n = preg_replace('/(?<!\b\p{L})\.$/u', '', rtrim($n, '،, ')); // a closing full stop, not an initial's
            if (str_contains($n, ',') || str_contains($n, '،')) {
                [$family, $given] = array_map('trim', preg_split('/[,،]/u', $n, 2));

                return ['family' => $family, 'given' => $given, 'whole' => false];
            }
            // Arabic names, and organizations, are cited as written.
            if (preg_match('/\p{Arabic}/u', $n) || ! str_contains($n, ' ')) {
                return ['family' => $n, 'given' => '', 'whole' => true];
            }
            $parts = preg_split('/\s+/u', $n);
            $family = array_pop($parts);

            return ['family' => $family, 'given' => implode(' ', $parts), 'whole' => false];
        })->values()->all();
    }

    private static function initials(string $given, string $dot = '. ', string $hyphen = '-'): string
    {
        $out = [];
        foreach (preg_split('/\s+/u', trim($given)) as $part) {
            if ($part === '') {
                continue;
            }
            $out[] = implode($hyphen, array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)).rtrim($dot), explode('-', $part)));
        }

        return implode($dot === '' ? '' : ' ', $out);
    }

    private function title(): string
    {
        return trim($this->b->title.($this->b->subtitle ? ': '.$this->b->subtitle : ''));
    }

    private function doiUrl(): ?string
    {
        return $this->b->doi ? DoiLookup::url($this->b->doi) : null;
    }

    /** @return array{text: string, html: string} */
    public function apa(): array
    {
        $names = array_map(fn ($a) => $a['whole'] || $a['given'] === '' ? $a['family'] : $a['family'].', '.self::initials($a['given']), $this->authors());
        $n = count($names);
        $who = match (true) {
            $n === 0 => '',
            $n === 1 => $names[0],
            $n <= 20 => implode(', ', array_slice($names, 0, -1)).', & '.$names[$n - 1],
            default => implode(', ', array_slice($names, 0, 19)).', … '.$names[$n - 1],
        };
        $year = '('.($this->b->year ?: 'n.d.').').';
        $title = $this->title();
        $doi = $this->doiUrl();

        if ($this->isArticle()) {
            $vol = $this->b->volume ? ', '.$this->b->volume : '';
            $iss = $this->b->issue ? '('.$this->b->issue.')' : '';
            $pp = $this->b->page_range ? ', '.$this->b->page_range : '';
            $text = trim(($who ? $who.' ' : '').$year.' '.rtrim($title, '.').'. '.$this->b->journal.$vol.$iss.$pp.'.'.($doi ? ' '.$doi : ''));
            $html = e(($who ? $who.' ' : '').$year.' '.rtrim($title, '.').'. ').'<i>'.e($this->b->journal).($vol ? e($vol) : '').'</i>'.e($iss.$pp.'.').($doi ? ' '.$this->link($doi) : '');
        } else {
            $ed = $this->b->edition ? ' ('.self::ordinalEdition($this->b->edition).')' : '';
            $pub = $this->b->publisher ? ' '.rtrim($this->b->publisher, '.').'.' : '';
            $who = $who ?: rtrim($title, '.');
            $head = $this->b->authors ? $who.' '.$year.' ' : $who.'. '.$year;
            $text = trim($head.($this->b->authors ? rtrim($title, '.').$ed.'.' : '').$pub.($doi ? ' '.$doi : ''));
            $html = $this->b->authors
                ? e($who.' '.$year.' ').'<i>'.e(rtrim($title, '.')).'</i>'.e($ed.'.'.$pub).($doi ? ' '.$this->link($doi) : '')
                : '<i>'.e($who).'</i>'.e('. '.$year.$pub).($doi ? ' '.$this->link($doi) : '');
        }

        return ['text' => $text, 'html' => $html];
    }

    /** @return array{text: string, html: string} */
    public function vancouver(): array
    {
        $names = array_map(fn ($a) => $a['whole'] || $a['given'] === '' ? $a['family'] : $a['family'].' '.self::initials($a['given'], '', ''), $this->authors());
        $who = count($names) > 6 ? implode(', ', array_slice($names, 0, 6)).', et al' : implode(', ', $names);
        $title = rtrim($this->title(), '.');
        if ($this->isArticle()) {
            $where = $this->b->year.($this->b->volume ? ';'.$this->b->volume : '').($this->b->issue ? '('.$this->b->issue.')' : '').($this->b->page_range ? ':'.$this->b->page_range : '');
            $text = ($who ? $who.'. ' : '').$title.'. '.$this->b->journal.'. '.$where.'.'.($this->b->doi ? ' doi:'.$this->b->doi : '');
        } else {
            $ed = $this->b->edition ? ' '.self::ordinalEdition($this->b->edition).'.' : '';
            $text = ($who ? $who.'. ' : '').$title.'.'.$ed.($this->b->publisher ? ' '.rtrim($this->b->publisher, '.').';' : '').($this->b->year ? ' '.$this->b->year : '').'.'.($this->b->doi ? ' doi:'.$this->b->doi : '');
        }
        $text = preg_replace('/\s+/u', ' ', str_replace(['..', ' ;', ';.'], ['.', ';', '.'], trim($text)));

        return ['text' => $text, 'html' => e($text)];
    }

    public function bibtex(): string
    {
        $first = $this->authors()[0]['family'] ?? 'ref';
        $key = Str::slug(Str::ascii($first), '') ?: 'ref';
        $key .= $this->b->year ?: '';
        $fields = array_filter([
            'author' => implode(' and ', array_map(fn ($a) => $a['whole'] ? '{'.$a['family'].'}' : trim($a['family'].', '.$a['given'], ', '), $this->authors())),
            'title' => '{'.$this->title().'}',
            'journal' => $this->b->journal,
            'year' => $this->b->year,
            'volume' => $this->b->volume,
            'number' => $this->b->issue,
            'pages' => $this->b->page_range ? str_replace('-', '--', $this->b->page_range) : null,
            'publisher' => $this->isArticle() ? null : $this->b->publisher,
            'edition' => $this->b->edition,
            'isbn' => $this->b->isbn,
            'doi' => $this->b->doi,
            'url' => $this->doiUrl(),
        ]);
        $lines = array_map(fn ($k, $v) => "  {$k} = {{$v}}", array_keys($fields), $fields);

        return '@'.($this->isArticle() ? 'article' : 'book').'{'.$key.",\n".implode(",\n", $lines)."\n}\n";
    }

    public function ris(): string
    {
        $out = ['TY  - '.($this->isArticle() ? 'JOUR' : 'BOOK')];
        foreach ($this->authors() as $a) {
            $out[] = 'AU  - '.($a['whole'] ? $a['family'] : trim($a['family'].', '.$a['given'], ', '));
        }
        [$sp, $ep] = array_pad(explode('-', (string) $this->b->page_range, 2), 2, null);
        foreach ([
            'TI' => $this->title(), 'T2' => $this->b->journal, 'PY' => $this->b->year, 'VL' => $this->b->volume, 'IS' => $this->b->issue,
            'SP' => $sp ?: null, 'EP' => $ep, 'PB' => $this->isArticle() ? null : $this->b->publisher, 'ET' => $this->b->edition,
            'SN' => $this->b->isbn, 'DO' => $this->b->doi, 'UR' => $this->doiUrl(), 'LA' => $this->b->language,
            'AB' => $this->b->description ? Str::limit(preg_replace('/\s+/u', ' ', $this->b->description), 2000) : null,
        ] as $tag => $v) {
            if (filled($v)) {
                $out[] = "{$tag}  - {$v}";
            }
        }
        $out[] = 'ER  - ';

        return implode("\r\n", $out)."\r\n";
    }

    /** All four, for the «استشهاد» window. */
    public function all(): array
    {
        return ['apa' => $this->apa(), 'vancouver' => $this->vancouver(), 'bibtex' => $this->bibtex(), 'ris' => $this->ris(), 'doi_url' => $this->doiUrl()];
    }

    private static function ordinalEdition(string $edition): string
    {
        if (! preg_match('/^\s*(\d+)\s*(?:st|nd|rd|th)?\.?\s*(?:ed(?:ition)?\.?)?\s*$/i', $edition, $m)) {
            return trim($edition);
        }
        $n = (int) $m[1];
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix.' ed.';
    }

    private function link(string $url): string
    {
        return '<a href="'.e($url).'">'.e($url).'</a>';
    }
}
