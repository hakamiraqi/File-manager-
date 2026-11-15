<?php

namespace App\Services\Library;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A DOI's record, from whichever agency issued it (Crossref, DataCite, mEDRA…): doi.org answers
 * every one of them in the same citation format (CSL-JSON).
 */
class DoiLookup
{
    /** The DOI inside whatever was pasted: «doi:10…», «https://doi.org/10…», a publisher link… */
    public static function normalize(?string $input): ?string
    {
        $input = urldecode(trim((string) $input));
        if (! preg_match('~(10\.\d{4,9}/[^\s"<>]+)~', $input, $m)) {
            return null;
        }

        return strtolower(rtrim($m[1], '.,;)]}'));
    }

    public static function url(string $doi): string
    {
        return 'https://doi.org/'.$doi;
    }

    /** @return array<string, mixed>|null the book fields, or null when the DOI is unknown or doi.org is unreachable */
    public function find(string $input): ?array
    {
        $doi = self::normalize($input);
        if (! $doi) {
            return null;
        }
        try {
            $r = Http::timeout(12)->withHeaders(['Accept' => 'application/vnd.citationstyles.csl+json'])
                ->withUserAgent('FileNest library (mailto:library@filenest.local)')
                ->get(self::url($doi));
        } catch (Throwable) {
            return null;
        }
        $csl = $r->successful() ? $r->json() : null;

        return is_array($csl) && ! empty($csl['title']) ? self::fromCsl($csl, $doi) : null;
    }

    /** CSL-JSON → the catalogue's fields. */
    public static function fromCsl(array $c, string $doi): array
    {
        $first = fn ($v) => is_array($v) ? ($v[0] ?? null) : $v;
        $names = collect($c['author'] ?? $c['editor'] ?? [])->map(fn ($a) => isset($a['family'])
            ? trim($a['family'].(isset($a['given']) ? ', '.$a['given'] : ''))
            : ($a['literal'] ?? $a['name'] ?? null))->filter()->values();
        $date = $c['issued']['date-parts'][0] ?? $c['published-print']['date-parts'][0] ?? $c['published-online']['date-parts'][0] ?? $c['created']['date-parts'][0] ?? [];
        $isArticle = in_array($c['type'] ?? '', ['article-journal', 'journal-article', 'article', 'article-magazine', 'article-newspaper', 'paper-conference'], true)
            || filled($first($c['container-title'] ?? null)) && ! in_array($c['type'] ?? '', ['book', 'monograph'], true);
        $abstract = isset($c['abstract']) ? trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(['</jats:p>', '</p>'], "\n", $c['abstract'])))) : null;
        $lang = strtolower(substr((string) ($c['language'] ?? ''), 0, 2));

        return array_filter([
            'doi' => $doi,
            'title' => trim(strip_tags((string) $first($c['title']))),
            'subtitle' => ($s = $first($c['subtitle'] ?? null)) ? trim(strip_tags($s)) : null,
            'authors' => $names->implode('; ') ?: null,
            'publisher' => $c['publisher'] ?? null,
            'year' => isset($date[0]) ? (string) $date[0] : null,
            'journal' => $isArticle ? ($first($c['container-title'] ?? null) ?: null) : null,
            'volume' => isset($c['volume']) ? (string) $c['volume'] : null,
            'issue' => isset($c['issue']) ? (string) $c['issue'] : null,
            'page_range' => isset($c['page']) ? str_replace('–', '-', (string) $c['page']) : null,
            'pages' => ! $isArticle && isset($c['number-of-pages']) && is_numeric($c['number-of-pages']) ? (int) $c['number-of-pages'] : null,
            'isbn' => ($i = $first($c['ISBN'] ?? null)) ? preg_replace('/[^0-9Xx]/', '', $i) : null,
            'edition' => isset($c['edition']) ? (string) $c['edition'] : null,
            'language' => ['en' => 'English', 'ar' => 'العربية', 'fr' => 'Français', 'de' => 'Deutsch'][$lang] ?? null,
            'subjects' => ! empty($c['subject']) ? implode('؛ ', (array) $c['subject']) : null,
            'description' => $abstract ? mb_substr(preg_replace('/^Abstract\s*/i', '', $abstract), 0, 5000) : null,
            'type' => $isArticle ? 'article' : 'book',
        ], fn ($v) => $v !== null && $v !== '');
    }
}
