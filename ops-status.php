<?php
// User, 11 Nov 10:58: a section on two bookcases fills both, not only the first. Its books, in
// title order, are split so each bookcase holds about half the copies (each copy's «الرف»).
use App\Models\Library\Book; use App\Models\Library\BookCopy; use App\Models\Library\LibraryShelf as Sh;
$pairs = Sh::where('kind', 'shelf')->whereNotNull('section_id')->orderBy('code')->get()->groupBy('section_id')->filter(fn ($g) => $g->count() > 1);
\Illuminate\Support\Facades\DB::transaction(function () use ($pairs) {
    foreach ($pairs as $sectionId => $cases) {
        // Bookcases in reading order: 1 then 2 (by the number in the shelf's name, else its code).
        $cases = $cases->sortBy(fn ($s) => preg_match('/(\d+)\s*$/', (string) $s->name, $m) ? (int) $m[1] : $s->code)->values();
        $books = Book::where('section_id', $sectionId)->withCount('copies')->get()->sortBy(fn ($b) => mb_strtolower(trim($b->title)))->values();
        $total = $books->sum('copies_count');
        $per = $total / $cases->count();
        $acc = 0;
        foreach ($books as $b) {
            $i = min($cases->count() - 1, (int) floor($acc / max($per, 1)));
            BookCopy::where('book_id', $b->id)->update(['shelf' => $cases[$i]->code]);
            $acc += $b->copies_count;
        }
    }
});
$map = app(\App\Services\Library\LibraryMap::class);
$stock = $map->stock();
foreach (Sh::where('kind', 'shelf')->orderBy('code')->get() as $s) {
    $first = BookCopy::where('shelf', $s->code)->join('books', 'books.id', '=', 'book_copies.book_id')->orderBy('books.title')->value('books.title');
    echo "SHELF {$s->code} {$s->name} copies=".($stock[$s->id]['n'] ?? 0).($first ? " first=".mb_substr($first, 0, 30) : '')."\n";
}
