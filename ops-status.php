<?php
// User, 11 Nov 10:53: the reading table is 3.5 m wide and 5.5 m long.
use App\Models\Library\LibraryShelf as Sh;
Sh::where('kind', 'table')->where('code', 'طاولة1')->first()?->fill(['x' => 3.0, 'y' => 3.85, 'rotation' => 0, 'width' => 3.5, 'depth' => 5.5])->save();
foreach (Sh::where('kind', 'table')->get() as $s) echo "AFTER {$s->code} x={$s->x} y={$s->y} w={$s->width} d={$s->depth}\n";
