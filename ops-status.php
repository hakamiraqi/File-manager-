<?php
// User, 11 Nov 10:50: the middle row stands against the far wall; the reading table back in the middle.
use App\Models\Library\LibraryShelf as Sh;
\Illuminate\Support\Facades\DB::transaction(function () {
    foreach (['M1' => 1.5, 'M2' => 2.5, 'M3' => 3.5, 'M4' => 4.5] as $code => $x) {
        Sh::where('kind', 'shelf')->where('code', $code)->first()?->fill(['x' => $x, 'y' => 0.25, 'rotation' => 0, 'double_sided' => false, 'group_key' => 'row-back'])->save();
    }
    Sh::firstOrNew(['kind' => 'table', 'code' => 'طاولة1'])->fill(['storey' => 0, 'x' => 3.0, 'y' => 4.2, 'rotation' => 0, 'width' => 1.4, 'depth' => 3.0, 'levels' => 1])->save();
});
foreach (Sh::orderBy('kind')->orderBy('code')->get() as $s) echo "AFTER {$s->kind} {$s->code} x={$s->x} y={$s->y} r={$s->rotation} w={$s->width} d={$s->depth}\n";
