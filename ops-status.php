<?php
// User, 11 Nov 14:12: chairs line both sides of each table, so the 1 m between the two tables
// is not a walkway: it is marked as an obstacle («كراسي») that routes go around.
use App\Models\Library\LibraryShelf as Sh;
Sh::firstOrNew(['kind' => 'obstacle', 'code' => 'كراسي'])->fill([
    'name' => 'كراسي بين الطاولتين', 'storey' => 0, 'x' => 3.0, 'y' => 3.85, 'rotation' => 0, 'width' => 1.0, 'depth' => 5.5,
    'height' => 0.9, 'color' => '#94a3b8', 'points' => [[-0.5, -2.75], [0.5, -2.75], [0.5, 2.75], [-0.5, 2.75]],
])->save();
foreach (Sh::whereIn('kind', ['table', 'obstacle'])->get() as $s) echo "AFTER {$s->kind} {$s->code} x={$s->x} y={$s->y} w={$s->width} d={$s->depth}\n";
