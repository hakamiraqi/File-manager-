<?php
use App\Models\Library\LibrarySection; use App\Models\Library\LibraryShelf; use App\Models\Library\Book;
foreach (LibrarySection::withCount('books')->orderBy('id')->get() as $s) echo "SEC {$s->id} p={$s->parent_id} sort={$s->sort} n={$s->books_count} loc=".json_encode($s->location)." ".$s->name."\n";
foreach (LibraryShelf::orderBy('id')->get() as $s) echo "SH {$s->id} {$s->kind} code={$s->code} name=".json_encode($s->name)." sec={$s->section_id} st={$s->storey} x={$s->x} y={$s->y} r={$s->rotation} w={$s->width} d={$s->depth} lv={$s->levels} ds=".(int)$s->double_sided." grp={$s->group_key}\n";
echo 'FLOOR '.json_encode(app(\App\Services\Library\LibraryMap::class)->floor(), JSON_UNESCAPED_UNICODE)."\n";
echo 'COPY SHELVES '.json_encode(\App\Models\Library\BookCopy::selectRaw('shelf, count(*) c')->groupBy('shelf')->orderByDesc('c')->limit(30)->pluck('c','shelf'), JSON_UNESCAPED_UNICODE)."\n";
