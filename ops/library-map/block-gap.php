<?php

/**
 * 2026-11-23, by request: readers can't walk between the two reading tables (the chairs fill
 * the 1 m gap), so the gap becomes an obstacle and the map's walking route goes around them.
 */

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Library\LibraryShelf;

$row = ['code' => 'GAP', 'name' => 'لا يوجد ممر بين الطاولتين', 'kind' => 'obstacle', 'storey' => 0, 'section_id' => null,
    'x' => 4.0, 'y' => 3.0, 'rotation' => 0, 'width' => 1.0, 'depth' => 4.0, 'levels' => 1,
    'points' => [[-0.5, -2.0], [0.5, -2.0], [0.5, 2.0], [-0.5, 2.0]], 'height' => 0.8,
    'double_sided' => false, 'locked' => false, 'color' => '#9ca3af'];

$gap = LibraryShelf::where('code', 'GAP')->first();
$gap ? $gap->update($row) : LibraryShelf::create($row);
echo "Gap between the tables blocked.\n";
foreach (LibraryShelf::orderBy('code')->get(['code', 'kind', 'x', 'y', 'width', 'depth']) as $s) {
    echo "  {$s->code} {$s->kind} x={$s->x} y={$s->y} {$s->width}x{$s->depth}\n";
}
