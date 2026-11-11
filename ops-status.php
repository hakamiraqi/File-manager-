<?php
// User, 11 Nov 10:55: the other way round, so Miscellaneous is the first bookcase from the door.
// The bookcases are mirrored left↔right (the door, desk and table stay); the codes follow the
// side they are now on (the Miscellaneous row is now R1–R6, Oral Diagnosis/Basic Sciences L1–L4).
use App\Models\Library\LibraryShelf as Sh;
\Illuminate\Support\Facades\DB::transaction(function () {
    $shelves = Sh::where('kind', 'shelf')->get();
    foreach ($shelves as $s) {
        $s->code = 'tmp-'.$s->id;
        $s->save();
    }
    foreach ($shelves as $s) {
        $old = $s->getOriginal('code');
        $s->x = round(6 - (float) $s->x, 2);
        $s->rotation = match ((int) $s->rotation) { 90 => 270, 270 => 90, default => (int) $s->rotation };
        $s->group_key = match ($s->group_key) { 'row-left' => 'row-right', 'row-right' => 'row-left', default => $s->group_key };
        $s->save();
    }
});
// New codes from each bookcase's place: right row from the door inwards, left row the same, back row right to left.
$right = Sh::where('kind', 'shelf')->where('x', '>', 5)->orderByDesc('y')->get();
$left = Sh::where('kind', 'shelf')->where('x', '<', 1)->orderByDesc('y')->get();
$back = Sh::where('kind', 'shelf')->where('y', '<', 0.5)->orderByDesc('x')->get();
foreach ([['R', $right], ['L', $left], ['M', $back]] as [$p, $list]) {
    foreach ($list->values() as $i => $s) { $s->code = $p.($i + 1); $s->save(); }
}
foreach (Sh::where('kind', 'shelf')->orderBy('code')->get() as $s) echo "AFTER {$s->code} {$s->name} x={$s->x} y={$s->y} r={$s->rotation} {$s->group_key}\n";
