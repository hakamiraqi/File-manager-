<?php

namespace App\Services\Library;

use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\LibrarySection;
use App\Models\Library\LibraryShelf;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The library floor plan and «أين الكتاب؟»: which bookcase (and level) holds each copy.
 *
 * A copy's «الرف» text is matched against bookcase codes: "A3" is bookcase A3, "A3-2" or "A3/2"
 * is level 2 of it. A copy with no matching code is placed on a bookcase that holds its book's
 * section (or the nearest parent section that has one).
 */
class LibraryMap
{
    public const SETTING = 'library_map';

    /** Folder (public disk) of the uploaded building plans drawn over. */
    public const PLANS = 'library-plans';

    /** @var array<int, int|null> section id → bookcase id, for one request */
    private array $sectionShelf = [];

    /** @return array{width: float, depth: float, entrance: array{x: float, y: float}, name: string, storeys: list<string>, separate_halls: bool} */
    public function floor(): array
    {
        $saved = json_decode((string) Setting::get(self::SETTING, ''), true) ?: [];
        $width = (float) ($saved['width'] ?? 20);
        $depth = (float) ($saved['depth'] ?? 14);

        return [
            'width' => $width,
            'depth' => $depth,
            'entrance' => [
                'x' => (float) ($saved['entrance']['x'] ?? $width / 2),
                'y' => (float) ($saved['entrance']['y'] ?? $depth),
            ],
            'name' => (string) ($saved['name'] ?? ''),
            // «طوابق البناية»: one name per floor, ground floor first.
            'storeys' => array_values(array_map('strval', $saved['storeys'] ?? [])) ?: ['الطابق الأرضي'],
            // «كل قاعة مستقلة»: readers see only the hall they are in, one at a time.
            'separate_halls' => (bool) ($saved['separate_halls'] ?? false),
            // «مخطط البناية»: a picture of each floor's plan, drawn over in the editor.
            'backgrounds' => array_values(array_map(fn ($b) => $b + ['url' => Storage::disk('public')->url($b['path'])], $saved['backgrounds'] ?? [])),
        ];
    }

    /** @return list<array{storey:int, path:string, x:float, y:float, width:float, aspect:float, opacity:float, hidden:bool}> */
    private function cleanBackgrounds(array $list): array
    {
        $out = [];
        foreach ($list as $b) {
            $path = (string) ($b['path'] ?? '');
            if (! str_starts_with($path, self::PLANS.'/') || str_contains($path, '..')) {
                continue;
            }
            $out[] = [
                'storey' => (int) $b['storey'], 'path' => $path,
                'x' => round((float) $b['x'], 2), 'y' => round((float) $b['y'], 2),
                'width' => round((float) $b['width'], 3), 'aspect' => round((float) $b['aspect'], 5),
                'opacity' => round((float) ($b['opacity'] ?? 0.5), 2), 'hidden' => (bool) ($b['hidden'] ?? false),
            ];
        }

        return $out;
    }

    public function saveFloor(array $floor): void
    {
        Setting::set(self::SETTING, json_encode([
            'width' => (float) $floor['width'],
            'depth' => (float) $floor['depth'],
            'entrance' => ['x' => (float) $floor['entrance']['x'], 'y' => (float) $floor['entrance']['y']],
            'name' => (string) ($floor['name'] ?? ''),
            'storeys' => array_values(array_map(fn ($n) => trim((string) $n), $floor['storeys'] ?? [])) ?: ['الطابق الأرضي'],
            'separate_halls' => (bool) ($floor['separate_halls'] ?? false),
            'backgrounds' => $backgrounds = $this->cleanBackgrounds($floor['backgrounds'] ?? []),
        ], JSON_UNESCAPED_UNICODE));
        // Plans no longer used are deleted.
        $keep = array_column($backgrounds, 'path');
        foreach (Storage::disk('public')->files(self::PLANS) as $file) {
            if (! in_array($file, $keep, true)) {
                Storage::disk('public')->delete($file);
            }
        }
    }

    /**
     * «كتب بلا مكان» for the map check: books none of whose copies is on a bookcase, by its
     * «الرف» code or by its section.
     *
     * @return array{count:int, books:list<array{id:int, title:string}>}
     */
    public function unplaced(int $sample = 10): array
    {
        $shelves = LibraryShelf::where('kind', 'shelf')->get();
        $count = 0;
        $books = [];
        Book::with('copies')->orderBy('title')->chunk(300, function ($chunk) use ($shelves, &$count, &$books, $sample) {
            foreach ($chunk as $book) {
                if ($shelves->isNotEmpty() && $this->locate($book, $shelves) !== []) {
                    continue;
                }
                $count++;
                if (count($books) < $sample) {
                    $books[] = ['id' => $book->id, 'title' => $book->title];
                }
            }
        });

        return ['count' => $count, 'books' => $books];
    }

    /** Every bookcase as the 3D map draws it. */
    public function shelves(): Collection
    {
        $paths = collect(LibrarySection::options())->pluck('path', 'id');

        return LibraryShelf::orderBy('code')->get()->map(fn (LibraryShelf $s) => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'kind' => $s->kind, 'storey' => $s->storey,
            'section_id' => $s->section_id, 'section' => $s->section_id ? ($paths[$s->section_id] ?? null) : null,
            'x' => $s->x, 'y' => $s->y, 'rotation' => $s->rotation, 'width' => $s->width, 'depth' => $s->depth,
            'levels' => $s->levels, 'double_sided' => $s->double_sided, 'color' => $s->color,
            'points' => $s->points, 'height' => $s->height,
        ]);
    }

    /** "A3-2" → [shelf A3, 2]; "A3" → [shelf A3, null]; unknown → null. */
    public function parseShelf(?string $text, ?Collection $shelves = null): ?array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        $shelves ??= LibraryShelf::where('kind', 'shelf')->get();
        $byCode = $shelves->keyBy(fn ($s) => mb_strtolower($s->code));

        if ($hit = $byCode->get(mb_strtolower($text))) {
            return [$hit, null];
        }
        if (preg_match('/^(.+?)\s*[-\/\s،,]\s*(\d{1,2})$/u', $text, $m) && ($hit = $byCode->get(mb_strtolower(trim($m[1]))))) {
            $level = (int) $m[2];

            return [$hit, $level >= 1 && $level <= $hit->levels ? $level : null];
        }

        return null;
    }

    /**
     * Where a book's copies are: one entry per bookcase, available copies first.
     *
     * @return list<array{shelf_id:int, code:string, level:?int, available:int, total:int, by_section:bool, barcodes:list<string>}>
     */
    public function locate(Book $book, ?Collection $shelves = null): array
    {
        $shelves ??= LibraryShelf::where('kind', 'shelf')->get();
        if ($shelves->isEmpty()) {
            return [];
        }
        $copies = $book->relationLoaded('copies') ? $book->copies : $book->copies()->get();
        $copies = $copies->whereNotIn('status', [BookCopy::LOST]);

        $sectionShelf = $this->shelfForSection($book->section_id, $shelves);
        $spots = [];
        foreach ($copies as $copy) {
            [$shelf, $level, $bySection] = $this->spotOf($copy, $book->section_id, $shelves) ?? [null, null, true];
            if (! $shelf) {
                continue;
            }
            $key = $shelf->id.'|'.($level ?? '');
            $spots[$key] ??= ['shelf_id' => $shelf->id, 'code' => $shelf->code, 'level' => $level, 'available' => 0, 'total' => 0, 'by_section' => $bySection, 'barcodes' => []];
            $spots[$key]['total']++;
            $spots[$key]['barcodes'][] = $copy->barcode;
            if ($copy->status === BookCopy::AVAILABLE) {
                $spots[$key]['available']++;
            }
        }
        // No copy (or none placed) but the section has a bookcase: still show where it belongs.
        if ($spots === [] && $sectionShelf) {
            $spots[] = ['shelf_id' => $sectionShelf->id, 'code' => $sectionShelf->code, 'level' => null, 'available' => 0, 'total' => 0, 'by_section' => true, 'barcodes' => []];
        }

        return collect($spots)->sortByDesc('available')->values()->all();
    }

    /**
     * Search results: the best spot of each book on one page (one bookcase query for all of them),
     * so the list can show «على الخريطة» with the bookcase code.
     *
     * @param  iterable<Book>  $books
     */
    public function attachSpots(iterable $books): void
    {
        $shelves = LibraryShelf::where('kind', 'shelf')->get();
        foreach ($books as $book) {
            $spot = $shelves->isEmpty() ? null : ($this->locate($book, $shelves)[0] ?? null);
            $book->setAttribute('map_spot', $spot ? ['code' => $spot['code'], 'level' => $spot['level']] : null);
            $book->unsetRelation('copies');
        }
    }

    /**
     * The bookcase one copy sits on: its «الرف» text when that names a bookcase, otherwise the
     * bookcase of its section. [bookcase, level|null, placed by section] or null.
     */
    public function spotOf(BookCopy $copy, ?int $sectionId, Collection $shelves): ?array
    {
        if ($parsed = $this->parseShelf($copy->shelf, $shelves)) {
            return [$parsed[0], $parsed[1], false];
        }
        $shelf = $this->shelfForSection($sectionId, $shelves);

        return $shelf ? [$shelf, null, true] : null;
    }

    private function shelfForSection(?int $sectionId, Collection $shelves): ?LibraryShelf
    {
        if ($sectionId && array_key_exists($sectionId, $this->sectionShelf)) {
            $id = $this->sectionShelf[$sectionId];

            return $id ? $shelves->firstWhere('id', $id) : null;
        }
        $start = $sectionId;
        $found = $this->walkSections($sectionId, $shelves);
        if ($start) {
            $this->sectionShelf[$start] = $found?->id;
        }

        return $found;
    }

    private function walkSections(?int $sectionId, Collection $shelves): ?LibraryShelf
    {
        for ($i = 0; $sectionId && $i < 20; $i++) {
            if ($hit = $shelves->firstWhere('section_id', $sectionId)) {
                return $hit;
            }
            $sectionId = LibrarySection::whereKey($sectionId)->value('parent_id');
        }

        return null;
    }
}
