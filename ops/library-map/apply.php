<?php

/**
 * One-off (2026-11-23, by request): lays out the College of Dentistry reading room on
 * «خريطة المكتبة» as drawn in the project's library-layout plan. Room 8 m x 6 m, bookcases on
 * the left, back and right walls, two reading tables in the middle, the librarian's desk at the
 * front left and the door 20 cm from the right wall. Piped into the app container:
 *   sudo docker exec -i filenest-app php < ops/library-map/apply.php
 * Refuses to overwrite a map that already has items.
 */

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Library\LibrarySection;
use App\Models\Library\LibraryShelf;
use App\Services\Library\LibraryMap;
use Illuminate\Support\Facades\DB;

$existing = LibraryShelf::count();
if ($existing > 0 && getenv('FORCE') !== '1') {
    fwrite(STDERR, "The map already has {$existing} items; nothing changed.\n");
    exit(1);
}

$norm = fn ($s) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
$sections = LibrarySection::all();
echo "Sections on the site:\n";
foreach ($sections as $s) {
    echo "  #{$s->id} {$s->path()}\n";
}
$find = function (array $names) use ($sections, $norm) {
    foreach ($names as $n) {
        $hit = $sections->first(fn ($s) => $norm($s->name) === $norm($n));
        if ($hit) {
            return $hit->id;
        }
    }

    return null;
};

$W = 8.0; $D = 6.0; $deep = 0.5;
$items = [];
$shelf = fn ($code, $name, $sec, $x, $y, $rot, $w) => [
    'code' => $code, 'name' => $name, 'kind' => 'shelf', 'section_id' => $sec,
    'x' => round($x, 2), 'y' => round($y, 2), 'rotation' => $rot, 'width' => round($w, 2), 'depth' => $deep, 'levels' => 5,
];

// Left wall (faces the room): from the back wall toward the entrance.
$left = [
    ['Oral Diagnosis 1', ['Oral Diagnosis']],
    ['Oral Diagnosis 2', ['Oral Diagnosis']],
    ['Basic Sciences 1', ['Basic sciences 1', 'Basic Sciences 1']],
    ['Basic Sciences 2', ['Basic Sciences 2']],
];
$bw = 5.0 / count($left);
foreach ($left as $i => [$name, $sec]) {
    $items[] = $shelf('L'.($i + 1), $name, $find($sec), $deep / 2, 0.5 + $bw * ($i + 0.5), 270, $bw);
}

// Right wall: R1 next to the entrance, R6 at the back.
$right = [
    ['Miscellaneous', ['Miscellaneous']],
    ['Methodological', ['Methodological']],
    ['Scientific Journals & Handbook', ['Hand Book', 'Handbook', 'Scientific Journals']],
    ['MSc & Thesis', ['Postgraduate Books', 'MSc & Thesis']],
    ['Conservative Esthetic Dentistry 1', ['Conservative &Esthetic Dentistry', 'Conservative & Esthetic Dentistry']],
    ['Conservative Esthetic Dentistry 2', ['Conservative &Esthetic Dentistry', 'Conservative & Esthetic Dentistry']],
];
$bw = 5.0 / count($right);
foreach ($right as $i => [$name, $sec]) {
    $items[] = $shelf('R'.($i + 1), $name, $find($sec), $W - $deep / 2, 5.5 - $bw * ($i + 0.5), 90, $bw);
}

// Back wall, facing the entrance: M1 on the right, M4 on the left.
$back = [
    ['Maxillofacial Surgery 1', ['Maxillofacial Surgery']],
    ['Maxillofacial Surgery 2', ['Maxillofacial Surgery']],
    ['POP & Periodontics', ['POP & periodontics']],
    ['Prosthodontics', ['Prosthodontic', 'Prosthodontics']],
];
$bw = 7.0 / count($back);
foreach ($back as $i => [$name, $sec]) {
    $items[] = $shelf('M'.($i + 1), $name, $find($sec), 7.5 - $bw * ($i + 0.5), $deep / 2, 0, $bw);
}

// Two reading tables, 2 x 4 m, 1 m apart, 0.5 m from the back bookcases.
foreach ([[2.5, 'طاولة قراءة 1'], [5.5, 'طاولة قراءة 2']] as $i => [$x, $name]) {
    $items[] = ['code' => 'T'.($i + 1), 'name' => $name, 'kind' => 'table', 'section_id' => null,
        'x' => $x, 'y' => 3.0, 'rotation' => 0, 'width' => 2.0, 'depth' => 4.0, 'levels' => 1];
}
// Librarian's desk: front left, along the entrance wall.
$items[] = ['code' => 'DESK', 'name' => 'مكتب أمين المكتبة', 'kind' => 'desk', 'section_id' => null,
    'x' => 1.4, 'y' => 5.6, 'rotation' => 0, 'width' => 1.6, 'depth' => 0.6, 'levels' => 1];
// Door: 1.2 m wide, 20 cm from the right wall.
$doorX = $W - 0.2 - 0.6;
$items[] = ['code' => 'DOOR', 'name' => 'المدخل', 'kind' => 'door', 'section_id' => null,
    'x' => $doorX, 'y' => $D, 'rotation' => 0, 'width' => 1.2, 'depth' => 0.2, 'levels' => 1];

DB::transaction(function () use ($items, $W, $D, $doorX) {
    $map = app(LibraryMap::class);
    $floor = $map->floor();
    $map->saveFloor(array_merge($floor, [
        'width' => $W, 'depth' => $D, 'name' => 'مكتبة كلية طب الأسنان',
        'entrance' => ['x' => $doorX, 'y' => $D],
    ]));
    LibraryShelf::query()->delete();
    foreach ($items as $row) {
        LibraryShelf::create($row + ['storey' => 0, 'double_sided' => false, 'locked' => false]);
    }
});

echo "\nSaved ".count($items)." items:\n";
foreach ($items as $r) {
    printf("  %-5s %-6s %-34s section=%s\n", $r['code'], $r['kind'], $r['name'], $r['section_id'] ?? '-');
}
