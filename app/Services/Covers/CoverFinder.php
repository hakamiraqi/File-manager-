<?php

namespace App\Services\Covers;

use App\Models\File;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks for a book's cover online (2026-11-12): first by the ISBN printed inside the book
 * (its text is already extracted), then — if allowed — by the file's name, accepting only a
 * close title match. Open Library (free, no key) and, when an administrator added a key,
 * Google Books. Nothing about who reads the file is sent, only an ISBN or a title.
 */
class CoverFinder
{
    /** Where cover pictures may be downloaded from (also for covers chosen by hand). */
    public const HOSTS = ['covers.openlibrary.org', 'archive.org', 'books.google.com', 'books.googleusercontent.com'];

    private const UA = 'FileNest/1.0 (university library; covers)';

    /** Thrown when the internet cannot be reached: the run stops without marking any file. */
    public static function offline(Throwable $e): bool
    {
        return $e instanceof ConnectionException;
    }

    // ------------------------------------------------------------------ ISBN

    /** ISBNs printed in the text (checksums checked), most likely first. @return list<string> */
    public static function isbns(?string $text): array
    {
        if (! $text) {
            return [];
        }
        $text = strtr(mb_substr($text, 0, 30000).' '.mb_substr($text, -8000), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        preg_match_all('/(?<![\d])(97[89][\d\- ]{10,16}[\dX]|[\d][\d\- ]{8,12}[\dX])(?![\d])/iu', $text, $m);
        $out = [];
        foreach ($m[1] as $raw) {
            $d = strtoupper(preg_replace('/[^\dX]/i', '', $raw));
            if ((strlen($d) === 13 && self::isbn13ok($d)) || (strlen($d) === 10 && self::isbn10ok($d))) {
                // Prefer the ones written next to the word ISBN / ردمك.
                $near = preg_match('/(isbn|ردمك|رقم الإيداع)[^\d]{0,15}'.preg_quote(trim($raw), '/').'/iu', $text) === 1;
                $out[$d] = ($out[$d] ?? 0) + ($near ? 10 : 1);
            }
        }
        arsort($out);

        return array_slice(array_keys($out), 0, 3);
    }

    private static function isbn13ok(string $d): bool
    {
        if (! ctype_digit($d) || ! in_array(substr($d, 0, 3), ['978', '979'], true)) {
            return false;
        }
        $s = 0;
        for ($i = 0; $i < 12; $i++) {
            $s += (int) $d[$i] * ($i % 2 ? 3 : 1);
        }

        return (10 - $s % 10) % 10 === (int) $d[12];
    }

    private static function isbn10ok(string $d): bool
    {
        if (! preg_match('/^\d{9}[\dX]$/', $d) || preg_match('/^(\d)\1{9}$/', $d)) {
            return false;
        }
        $s = 0;
        for ($i = 0; $i < 10; $i++) {
            $s += ($d[$i] === 'X' ? 10 : (int) $d[$i]) * (10 - $i);
        }

        return $s % 11 === 0;
    }

    // ------------------------------------------------------------------ titles

    /** A searchable title from the file's name, or null when the name is not a title (IMG_1234, scan 3…). */
    public static function titleFromName(string $name): ?string
    {
        $t = pathinfo($name, PATHINFO_FILENAME);
        $t = preg_replace('/[\(\[\{][^\)\]\}]*[\)\]\}]/u', ' ', $t);
        $t = preg_replace('/[_\.\-–—+]+/u', ' ', $t);
        $t = preg_replace('/\b(pdf|ebook|scan(ned)?|copy|final|v\d+|ocr|compressed|book)\b/iu', ' ', $t);
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if (mb_strlen($t) < 6 || preg_match('/^(img|dsc|scan|doc|file|document|new|untitled|مستند|ملف|صورة)\s*\d*$/iu', $t)
            || preg_match('/^[\d\s]+$/u', $t) || count(preg_split('/\s+/u', $t)) < 2) {
            return null;
        }

        return $t;
    }

    public static function normalize(string $v): string
    {
        $v = mb_strtolower($v);
        $v = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $v);
        $v = strtr($v, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $v = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $v);
        $v = preg_replace('/^(the|a|an|ال)\s+/u', '', trim($v));

        return trim(preg_replace('/\s+/u', ' ', $v));
    }

    /** 0..1: how sure we are the found title is this book's title. */
    public static function similarity(string $wanted, string $found): float
    {
        $a = self::normalize($wanted);
        $b = self::normalize(preg_replace('/\s*[:–—].*$/u', '', $found)); // without the subtitle
        $full = self::normalize($found);
        if ($a === '' || $b === '') {
            return 0;
        }
        if ($a === $b || $a === $full) {
            return 1;
        }
        $wa = array_unique(preg_split('/\s+/u', $a));
        $wb = array_unique(preg_split('/\s+/u', $full));
        $common = count(array_intersect($wa, $wb));

        return $common / max(count($wa), count(array_unique(preg_split('/\s+/u', $b))));
    }

    // ------------------------------------------------------------------ the services

    /**
     * Candidates for a query (title words or an ISBN): [{url, title, author, year, source, isbn}].
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 8): array
    {
        $query = trim($query);
        $isbn = preg_replace('/[^\dX]/i', '', $query);
        $isIsbn = (strlen($isbn) === 13 && self::isbn13ok($isbn)) || (strlen($isbn) === 10 && self::isbn10ok(strtoupper($isbn)));
        $out = [];
        if (CoverSettings::on('covers_openlibrary')) {
            $out = array_merge($out, $isIsbn ? $this->openLibraryIsbn($isbn) : $this->openLibrarySearch($query, $limit));
        }
        if (CoverSettings::on('covers_google') && CoverSettings::googleKey()) {
            $out = array_merge($out, $this->google($isIsbn ? 'isbn:'.$isbn : $query, $limit));
        }
        $seen = [];

        return array_values(array_filter($out, function ($c) use (&$seen) {
            if (isset($seen[$c['url']])) {
                return false;
            }

            return $seen[$c['url']] = true;
        }));
    }

    /** The automatic search for one file: the best cover, or null. */
    public function findFor(File $file): ?array
    {
        foreach (self::isbns($file->content_text) as $isbn) {
            $hits = $this->search($isbn, 3);
            if ($hits) {
                return $hits[0] + ['isbn' => $isbn, 'how' => 'isbn'];
            }
        }
        if (! CoverSettings::on('covers_title_search') || ! ($title = self::titleFromName((string) $file->name))) {
            return null;
        }
        $best = null;
        $score = 0;
        foreach ($this->search($title, 6) as $c) {
            $s = self::similarity($title, (string) $c['title']);
            if ($s > $score) {
                [$best, $score] = [$c, $s];
            }
        }

        return $best && $score >= 0.85 ? $best + ['how' => 'title', 'score' => round($score, 2)] : null;
    }

    private function http()
    {
        return Http::timeout(20)->connectTimeout(8)->withHeaders(['User-Agent' => self::UA])->retry(1, 1500, fn ($e) => ! self::offline($e), false);
    }

    private function openLibraryIsbn(string $isbn): array
    {
        $r = $this->http()->get("https://openlibrary.org/isbn/{$isbn}.json");
        if (! $r->successful()) {
            return [];
        }
        $cover = collect($r->json('covers') ?? [])->first(fn ($c) => is_int($c) && $c > 0);
        if (! $cover) {
            return [];
        }

        return [[
            'url' => "https://covers.openlibrary.org/b/id/{$cover}-L.jpg",
            'thumb' => "https://covers.openlibrary.org/b/id/{$cover}-M.jpg",
            'title' => (string) ($r->json('title') ?? ''),
            'author' => '',
            'year' => (string) ($r->json('publish_date') ?? ''),
            'source' => 'openlibrary',
            'isbn' => $isbn,
        ]];
    }

    private function openLibrarySearch(string $q, int $limit): array
    {
        $r = $this->http()->get('https://openlibrary.org/search.json', [
            'q' => $q, 'limit' => $limit, 'fields' => 'title,author_name,cover_i,first_publish_year,isbn',
        ]);
        if (! $r->successful()) {
            return [];
        }
        $out = [];
        foreach ($r->json('docs') ?? [] as $d) {
            if (empty($d['cover_i'])) {
                continue;
            }
            $out[] = [
                'url' => "https://covers.openlibrary.org/b/id/{$d['cover_i']}-L.jpg",
                'thumb' => "https://covers.openlibrary.org/b/id/{$d['cover_i']}-M.jpg",
                'title' => (string) ($d['title'] ?? ''),
                'author' => implode('، ', array_slice($d['author_name'] ?? [], 0, 2)),
                'year' => (string) ($d['first_publish_year'] ?? ''),
                'source' => 'openlibrary',
                'isbn' => $d['isbn'][0] ?? null,
            ];
        }

        return $out;
    }

    private function google(string $q, int $limit): array
    {
        $r = $this->http()->get('https://www.googleapis.com/books/v1/volumes', [
            'q' => $q, 'maxResults' => min(20, $limit), 'printType' => 'books', 'key' => CoverSettings::googleKey(),
        ]);
        if (! $r->successful()) {
            return [];
        }
        $out = [];
        foreach ($r->json('items') ?? [] as $it) {
            $v = $it['volumeInfo'] ?? [];
            $img = $v['imageLinks']['thumbnail'] ?? $v['imageLinks']['smallThumbnail'] ?? null;
            if (! $img) {
                continue;
            }
            $img = preg_replace('/&edge=curl/', '', str_replace('http://', 'https://', $img));
            $isbn = collect($v['industryIdentifiers'] ?? [])->firstWhere('type', 'ISBN_13')['identifier'] ?? null;
            $out[] = [
                'url' => preg_replace('/zoom=\d/', 'zoom=1', $img),
                'thumb' => $img,
                'title' => trim(($v['title'] ?? '').(isset($v['subtitle']) ? ': '.$v['subtitle'] : '')),
                'author' => implode('، ', array_slice($v['authors'] ?? [], 0, 2)),
                'year' => substr((string) ($v['publishedDate'] ?? ''), 0, 4),
                'source' => 'google',
                'isbn' => $isbn,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ the picture

    public static function allowedUrl(string $url): bool
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? '') === 'https' && in_array(strtolower($p['host'] ?? ''), self::HOSTS, true)
            || (($p['scheme'] ?? '') === 'https' && str_ends_with(strtolower($p['host'] ?? ''), '.archive.org'));
    }

    /** The picture at $url as a JPEG at most 800 high, or null when it is not a usable cover. */
    public function download(string $url): ?string
    {
        if (! self::allowedUrl($url)) {
            return null;
        }
        $r = Http::timeout(30)->connectTimeout(8)->withHeaders(['User-Agent' => self::UA])->get($url);
        if (! $r->successful() || strlen($r->body()) < 1500) {
            return null;
        }

        return self::toJpeg($r->body());
    }

    /** Any picture (JPEG/PNG/WebP/GIF) as a JPEG at most 800 high; null when not a picture or too small. */
    public static function toJpeg(string $bytes): ?string
    {
        $im = @imagecreatefromstring($bytes);
        if (! $im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 60 || $h < 80) {
            imagedestroy($im);

            return null;
        }
        $scale = min(1, 800 / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($out, null, 86);
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);
        imagedestroy($out);

        return $jpeg;
    }
}
