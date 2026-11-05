<?php

namespace App\Services\Library;

use Illuminate\Support\Facades\Http;

/** Fills a book form from its ISBN using Open Library (needs internet; empty result otherwise). */
class IsbnLookup
{
    /** @return array<string, mixed>|null */
    public function find(string $isbn): ?array
    {
        $isbn = preg_replace('/[^0-9Xx]/', '', $isbn);
        if (! in_array(strlen($isbn), [10, 13], true)) {
            return null;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get('https://openlibrary.org/api/books', [
                'bibkeys' => "ISBN:{$isbn}",
                'format' => 'json',
                'jscmd' => 'data',
            ]);
        } catch (\Throwable) {
            return null;
        }

        $data = $response->ok() ? ($response->json("ISBN:{$isbn}") ?? null) : null;
        if (! $data) {
            return null;
        }

        preg_match('/\d{4}/', (string) ($data['publish_date'] ?? ''), $year);

        return [
            'isbn' => $isbn,
            'title' => $data['title'] ?? '',
            'subtitle' => $data['subtitle'] ?? null,
            'authors' => collect($data['authors'] ?? [])->pluck('name')->implode('، '),
            'publisher' => collect($data['publishers'] ?? [])->pluck('name')->first(),
            'year' => $year[0] ?? null,
            'pages' => $data['number_of_pages'] ?? null,
            'cover_url' => $data['cover']['large'] ?? $data['cover']['medium'] ?? null,
        ];
    }
}
