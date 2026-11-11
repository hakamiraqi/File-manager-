<?php
// User, 11 Nov 11:12: the reading table as two tables side by side along its length, 1 m apart
// (same outer size, 3.5 m × 5.5 m: two tables 1.25 m wide).
use App\Models\Library\LibraryShelf as Sh;
\Illuminate\Support\Facades\DB::transaction(function () {
    Sh::where('kind', 'table')->where('code', 'طاولة1')->first()?->fill(['x' => 1.875, 'y' => 3.85, 'rotation' => 0, 'width' => 1.25, 'depth' => 5.5])->save();
    Sh::firstOrNew(['kind' => 'table', 'code' => 'طاولة2'])->fill(['storey' => 0, 'x' => 4.125, 'y' => 3.85, 'rotation' => 0, 'width' => 1.25, 'depth' => 5.5, 'levels' => 1])->save();
});
foreach (Sh::where('kind', 'table')->orderBy('code')->get() as $s) echo "AFTER {$s->code} x={$s->x} y={$s->y} w={$s->width} d={$s->depth}\n";
