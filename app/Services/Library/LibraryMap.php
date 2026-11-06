<?php

namespace App\Services\Library;

use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\LibrarySection;
use App\Models\Library\LibraryShelf;
use App\Models\Setting;
use Illuminate\Support\Collection;

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

    /** @return array{width: float, depth: float, entrance: array{x: float, y: float}, name: string} */
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
        ];
    }

    public function saveFloor(array $floor): void
    {
        Setting::set(self::SETTING, json_encode([
            'width' => (float) $floor['width'],
            'depth' => (float) $floor['depth'],
            'entrance' => ['x' => (float) $floor['entrance']['x'], 'y' => (float) $floor['entrance']['y']],
            'name' => (string) ($floor['name'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** Every bookcase as the 3D map draws it. */
    public function shelves(): Collection
    {
        $paths = collect(LibrarySection::options())->pluck('path', 'id');

        return LibraryShelf::orderBy('code')->get()->map(fn (LibraryShelf $s) => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'kind' => $s->kind,
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
    public function locate(Book $book): array
    {
        $shelves = LibraryShelf::where('kind', 'shelf')->get();
        if ($shelves->isEmpty()) {
            return [];
        }
        $copies = $book->relationLoaded('copies') ? $book->copies : $book->copies()->get();
        $copies = $copies->whereNotIn('status', [BookCopy::LOST]);

        $sectionShelf = $this->shelfForSection($book->section_id, $shelves);
        $spots = [];
        foreach ($copies as $copy) {
            $parsed = $this->parseShelf($copy->shelf, $shelves);
            [$shelf, $level, $bySection] = $parsed ? [$parsed[0], $parsed[1], false] : [$sectionShelf, null, true];
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

    private function shelfForSection(?int $sectionId, Collection $shelves): ?LibraryShelf
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
