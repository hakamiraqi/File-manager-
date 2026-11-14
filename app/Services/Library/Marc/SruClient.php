<?php

namespace App\Services\Library\Marc;

use App\Models\Setting;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Copy cataloguing (2026-11-14): finds a book's full MARC record in the world's library
 * catalogues over SRU — the web form of Z39.50, answered by the Library of Congress, the
 * big European union catalogues and every Koha (its Z39.50 server speaks SRU too). Plain
 * HTTP, so it works the same on Linux and on the Windows server, with no extension to install.
 */
class SruClient
{
    /** name, address, ISBN index, title index, author index, record schema. */
    public const DEFAULT_SERVERS = [
        ['name' => 'Library of Congress', 'url' => 'http://lx2.loc.gov:210/LCDB', 'isbn' => 'bath.isbn', 'title' => 'dc.title', 'author' => 'dc.creator', 'schema' => 'marcxml', 'on' => true],
        ['name' => 'K10plus (GBV/SWB)', 'url' => 'https://sru.k10plus.de/opac-de-627', 'isbn' => 'pica.isb', 'title' => 'pica.tit', 'author' => 'pica.per', 'schema' => 'marcxml', 'on' => true],
        ['name' => 'Deutsche Nationalbibliothek', 'url' => 'https://services.dnb.de/sru/dnb', 'isbn' => 'num', 'title' => 'tit', 'author' => 'per', 'schema' => 'MARC21-xml', 'on' => true],
    ];

    /** @return list<array{name: string, url: string, isbn: string, title: string, author: string, schema: string, on: bool}> */
    public static function servers(): array
    {
        $saved = json_decode((string) Setting::get('library_sru_servers'), true);

        return is_array($saved) && $saved ? array_values($saved) : self::DEFAULT_SERVERS;
    }

    public static function query(array $server, ?string $isbn, ?string $title, ?string $author): ?string
    {
        $q = fn (string $index, string $value) => $index.'="'.str_replace(['"', '\\'], '', $value).'"';
        if ($isbn) {
            return $q($server['isbn'], $isbn);
        }
        $parts = [];
        if ($title) {
            $parts[] = $q($server['title'], $title);
        }
        if ($author) {
            $parts[] = $q($server['author'], $author);
        }

        return $parts ? implode(' and ', $parts) : null;
    }

    /**
     * All enabled catalogues at once. @return array{results: list<array{server: string, book: array, marc_xml: string}>, errors: list<string>}
     */
    public function search(?string $isbn, ?string $title = null, ?string $author = null, int $perServer = 5): array
    {
        $servers = array_values(array_filter(self::servers(), fn ($s) => $s['on'] ?? true));
        $queries = [];
        foreach ($servers as $i => $s) {
            if ($query = self::query($s, $isbn, $title, $author)) {
                $queries[$i] = $query;
            }
        }
        if (! $queries) {
            return ['results' => [], 'errors' => []];
        }

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn ($i) => $pool->as((string) $i)->timeout(15)->connectTimeout(6)->withHeaders(['Accept' => 'application/xml'])->get($servers[$i]['url'], [
                'version' => '1.1', 'operation' => 'searchRetrieve', 'query' => $queries[$i],
                'maximumRecords' => $perServer, 'recordSchema' => $servers[$i]['schema'] ?? 'marcxml',
            ]),
            array_keys($queries),
        ));

        $results = [];
        $errors = [];
        $seen = [];
        foreach (array_keys($queries) as $i) {
            $r = $responses[(string) $i] ?? null;
            $name = $servers[$i]['name'];
            if (! $r instanceof Response || ! $r->successful()) {
                $errors[] = $name;

                continue;
            }
            foreach (MarcReader::xmlString($r->body()) as $record) {
                $book = MarcMapper::toBook($record)['book'];
                if (! $book['title']) {
                    continue;
                }
                $key = mb_strtolower(($book['isbn'] ?? '').'|'.$book['title'].'|'.($book['year'] ?? ''));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $record->remove('952', '995'); // their shelves are not ours
                $results[] = ['server' => $name, 'book' => $book, 'marc_xml' => MarcWriter::xml($record, true)];
            }
        }

        return ['results' => $results, 'errors' => $errors];
    }
}
