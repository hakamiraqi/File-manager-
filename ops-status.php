<?php
// User, 11 Nov 10:45: lay out the reading room (6 m × 8 m) and its bookcases as in the room:
// left row 6, middle row 4 (two-sided), right row 4, named after the «الكتب المصدرية» sections.
use App\Models\Library\LibrarySection as Sec; use App\Models\Library\LibraryShelf as Sh; use App\Models\Setting;
use Illuminate\Support\Facades\DB;
echo 'BEFORE SHELVES '.json_encode(Sh::all()->toArray(), JSON_UNESCAPED_UNICODE)."\n";
echo 'BEFORE FLOOR '.Setting::get(\App\Services\Library\LibraryMap::SETTING)."\n";
DB::transaction(function () {
    $meth = Sec::firstOrCreate(['name' => 'Methodological', 'parent_id' => 2]);
    $order = [12, $meth->id, 10, 11, 9, 8, 7, 6, 5, 3, 4];
    foreach ($order as $i => $id) Sec::whereKey($id)->update(['sort' => $i + 1]);

    $rows = [
        // code, name, section, x, y, rotation, two-sided, group
        ['L1', 'Miscellaneous', 12, 0.25, 6.5, 270, false, 'row-left'],
        ['L2', 'Methodological', $meth->id, 0.25, 5.5, 270, false, 'row-left'],
        ['L3', 'Scientific Journals & Handbook', 10, 0.25, 4.5, 270, false, 'row-left'],
        ['L4', 'MSc & Thesis', 11, 0.25, 3.5, 270, false, 'row-left'],
        ['L5', 'Conservative & Esthetic Dentistry 1', 9, 0.25, 2.5, 270, false, 'row-left'],
        ['L6', 'Conservative & Esthetic Dentistry 2', 9, 0.25, 1.5, 270, false, 'row-left'],
        ['M1', 'Maxillofacial Surgery 1', 8, 3.0, 5.6, 90, true, 'row-middle'],
        ['M2', 'Maxillofacial Surgery 2', 8, 3.0, 4.6, 90, true, 'row-middle'],
        ['M3', 'POP & Periodontics', 7, 3.0, 3.6, 90, true, 'row-middle'],
        ['M4', 'Prosthodontics', 6, 3.0, 2.6, 90, true, 'row-middle'],
        ['R1', 'Oral Diagnosis 1', 5, 5.75, 5.6, 90, false, 'row-right'],
        ['R2', 'Oral Diagnosis 2', 5, 5.75, 4.6, 90, false, 'row-right'],
        ['R3', 'Basic Sciences 1', 3, 5.75, 3.6, 90, false, 'row-right'],
        ['R4', 'Basic Sciences 2', 4, 5.75, 2.6, 90, false, 'row-right'],
    ];
    $old = Sh::where('kind', 'shelf')->orderBy('id')->get()->values();
    foreach ($rows as $i => [$code, $name, $sec, $x, $y, $r, $two, $grp]) {
        $s = $old[$i] ?? new Sh(['kind' => 'shelf', 'storey' => 0]);
        $s->fill(['code' => $code, 'name' => $name, 'section_id' => $sec, 'x' => $x, 'y' => $y, 'rotation' => $r,
            'width' => 1.0, 'depth' => 0.4, 'levels' => 5, 'double_sided' => $two, 'group_key' => $grp, 'storey' => 0])->save();
    }
    foreach ($old->slice(count($rows)) as $extra) $extra->delete();

    Sh::where('kind', 'table')->delete();
    Sh::where('kind', 'desk')->update(['x' => 1.6, 'y' => 7.55, 'rotation' => 0, 'width' => 1.6, 'depth' => 0.6]);
    Sh::where('kind', 'door')->update(['x' => 5.0, 'y' => 7.9, 'rotation' => 0, 'width' => 1.2]);
    foreach (Sh::where('kind', 'hall')->get() as $h) {
        $h->fill(['x' => 3.0, 'y' => 4.0, 'rotation' => 0, 'width' => 6.0, 'depth' => 8.0, 'points' => [[-3, -4], [3, -4], [3, 4], [-3, 4]]])->save();
    }

    $floor = json_decode((string) Setting::get(\App\Services\Library\LibraryMap::SETTING, ''), true) ?: [];
    $floor['width'] = 6; $floor['depth'] = 8; $floor['entrance'] = ['x' => 5.0, 'y' => 7.8];
    Setting::set(\App\Services\Library\LibraryMap::SETTING, json_encode($floor, JSON_UNESCAPED_UNICODE));
});
foreach (Sh::orderBy('id')->get() as $s) echo "AFTER {$s->id} {$s->kind} {$s->code} ".json_encode($s->name)." sec={$s->section_id} x={$s->x} y={$s->y} r={$s->rotation}\n";
foreach (Sec::where('parent_id', 2)->orderBy('sort')->get() as $s) echo "SEC {$s->sort} {$s->id} {$s->name}\n";
echo 'FLOOR '.Setting::get(\App\Services\Library\LibraryMap::SETTING)."\n";
$b = \App\Models\Library\Book::where('title', 'like', '20 common problems%')->with('copies')->first();
if ($b) echo 'BOOK SPOT '.json_encode(app(\App\Services\Library\LibraryMap::class)->locate($b, Sh::where('kind','shelf')->get()), JSON_UNESCAPED_UNICODE)."\n";
