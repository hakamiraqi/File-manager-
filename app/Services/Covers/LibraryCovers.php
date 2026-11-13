<?php

namespace App\Services\Covers;

use App\Models\File;
use App\Models\Library\Book;
use App\Services\Library\CoverStore;
use Illuminate\Support\Facades\Storage;

/**
 * Covers for the books on the shelves (2026-11-13). First from inside the server — the PDF
 * linked to the book, or a PDF/EPUB with the same ISBN or title (its cover or first-page
 * picture) — which works offline too; then online (Open Library / Google Books).
 */
class LibraryCovers
{
    public function __construct(private CoverFinder $finder, private CoverStore $store) {}

    /** The picture of a file in the drive that is this book, or null. @return array{0: File, 1: string}|null */
    public function localFor(Book $book): ?array
    {
        if ($book->file_id && ($f = File::find($book->file_id)) && ($bytes = $this->picture($f))) {
            return [$f, $bytes];
        }
        $isbn = $this->isbn($book);
        $files = File::query()->whereIn('extension', ['pdf', 'epub'])->whereNotNull('thumbnail_path');
        if ($isbn) {
            foreach ((clone $files)->whereIn('cover_isbn', self::bothForms($isbn))->orderByRaw('cover_source is null')->limit(3)->get() as $f) {
                if ($bytes = $this->picture($f)) {
                    return [$f, $bytes];
                }
            }
        }
        $title = trim((string) $book->title);
        $words = array_values(array_filter(explode(' ', CoverFinder::normalize($title)), fn ($w) => mb_strlen($w) >= 4));
        if (! $words) {
            return null;
        }
        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $words[0]).'%';
        $best = null;
        $score = 0;
        foreach ((clone $files)->where('name', 'like', $like)->limit(200)->get() as $f) {
            $name = CoverFinder::titleFromName((string) $f->name) ?? pathinfo((string) $f->name, PATHINFO_FILENAME);
            $s = self::sameBook($title, $name) + ($f->cover_source ? 0.01 : 0);
            if ($s > $score) {
                [$best, $score] = [$f, $s];
            }
        }
        if ($best && $score >= 0.85 && ($bytes = $this->picture($best))) {
            return [$best, $bytes];
        }

        return null;
    }

    /** 0..1 for "this file name is that book title" — whole title, or all its words when it is long enough. */
    public static function sameBook(string $title, string $name): float
    {
        $s = CoverFinder::similarity($title, $name);
        $wa = array_unique(array_filter(explode(' ', CoverFinder::normalize($title))));
        $wb = array_unique(array_filter(explode(' ', CoverFinder::normalize($name))));
        if (count($wa) >= 3 && ! array_diff($wa, $wb) && count($wb) <= count($wa) + 8) {
            $s = max($s, 0.9);
        }

        return $s;
    }

    /** The online search: [jpeg, source] or null. Throws when offline (the caller stops). */
    public function onlineFor(Book $book): ?array
    {
        if ($isbn = $this->isbn($book)) {
            foreach ($this->finder->search($isbn, 3) as $hit) {
                if ($jpeg = $this->finder->download($hit['url'])) {
                    return [$jpeg, $hit['source']];
                }
            }
        }
        $title = trim((string) $book->title);
        if (! CoverSettings::on('covers_title_search') || mb_strlen($title) < 6) {
            return null;
        }
        $author = trim(explode(',', (string) $book->authors)[0] ?? '');
        $best = null;
        $score = 0;
        foreach ($this->finder->search(trim($title.' '.$author), 6) ?: ($author ? $this->finder->search($title, 6) : []) as $c) {
            $s = CoverFinder::similarity($title, (string) $c['title']);
            if ($s > $score) {
                [$best, $score] = [$c, $s];
            }
        }
        if ($best && $score >= 0.85 && ($jpeg = $this->finder->download($best['url']))) {
            return [$jpeg, $best['source']];
        }

        return null;
    }

    public function apply(Book $book, string $bytes, string $source): void
    {
        $old = $book->cover_path;
        $book->forceFill([
            'cover_path' => $this->store->fromBytes($bytes),
            'cover_status' => 'found', 'cover_source' => $source, 'cover_checked_at' => now(),
        ])->save();
        $this->store->delete($old);
    }

    /** The same ISBN written as 10 and as 13 digits. @return list<string> */
    public static function bothForms(string $isbn): array
    {
        $out = [$isbn];
        if (strlen($isbn) === 10) {
            $d = '978'.substr($isbn, 0, 9);
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int) $d[$i] * ($i % 2 ? 3 : 1);
            }
            $out[] = $d.((10 - $sum % 10) % 10);
        } elseif (strlen($isbn) === 13 && str_starts_with($isbn, '978')) {
            $d = substr($isbn, 3, 9);
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $sum += (int) $d[$i] * (10 - $i);
            }
            $c = (11 - $sum % 11) % 11;
            $out[] = $d.($c === 10 ? 'X' : (string) $c);
        }

        return $out;
    }

    private function isbn(Book $book): ?string
    {
        return CoverFinder::isbns((string) $book->isbn)[0] ?? null;
    }

    private function picture(File $f): ?string
    {
        if (! $f->thumbnail_path) {
            return null;
        }
        try {
            $disk = $f->disk();
            if (! $disk->exists($f->thumbnail_path)) {
                return null;
            }
            $bytes = (string) $disk->get($f->thumbnail_path);
        } catch (\Throwable) {
            return null;
        }

        return strlen($bytes) > 1500 ? $bytes : null;
    }
}
