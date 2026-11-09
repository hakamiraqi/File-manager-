<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Attempt;
use App\Models\Exams\Computer;
use App\Models\Exams\MapItem;
use App\Services\Exams\HallMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «خريطة القاعات» (2026-11-09, by request): FileNest's library map turned to the exam halls.
 * Invigilators see every computer live (on, working, help, locked, cut off, wrong seat, wrong
 * clock) on a 2D or 3D plan and get walking directions to it, Waze style; the plan is laid out
 * in the same editor as the library's, with computers in place of bookcases.
 */
class HallMapController extends Controller
{
    public function __construct(private HallMap $map) {}

    private function canEdit(Request $request): bool
    {
        $u = $request->user();

        return $u->isAdmin() || $u->can('manage-exams');
    }

    public function show(Request $request): Response
    {
        $running = Attempt::whereNull('submitted_at')->where('started_at', '>=', now()->subHours(12))
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')->distinct()->pluck('exams.id');

        return Inertia::render('Exams/Monitor/HallMap', [
            'floor' => $this->map->floor(),
            'shelves' => $this->map->items(),
            'live' => $this->map->live($request->integer('exam') ?: null),
            'exams' => \App\Models\Exams\Exam::whereIn('id', $running)->get()->map(fn ($e) => ['id' => $e->id, 'title' => $e->text('title')])->values(),
            'examId' => $request->integer('exam') ?: null,
            'canEdit' => $this->canEdit($request),
            'startAt' => $request->integer('at') ?: null,
            'target' => $request->integer('pc') ?: null,
        ]);
    }

    /** The live states, polled by the map every few seconds. */
    public function live(Request $request): JsonResponse
    {
        return response()->json($this->map->live($request->integer('exam') ?: null));
    }

    public function edit(Request $request): Response
    {
        abort_unless($this->canEdit($request), 403);

        return Inertia::render('Exams/Monitor/HallMapEdit', [
            'floor' => $this->map->floor(),
            'shelves' => $this->map->items(),
            'computers' => Computer::orderBy('room')->get(['id', 'label', 'room', 'ip'])->sortBy([['room', 'asc'], ['label', SORT_NATURAL]])->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($this->canEdit($request), 403);
        $data = $request->validate([
            'floor.width' => ['required', 'numeric', 'min:4', 'max:300'],
            'floor.depth' => ['required', 'numeric', 'min:4', 'max:300'],
            'floor.name' => ['nullable', 'string', 'max:100'],
            'floor.entrance.x' => ['required', 'numeric', 'min:0', 'max:300'],
            'floor.entrance.y' => ['required', 'numeric', 'min:0', 'max:300'],
            'floor.storeys' => ['nullable', 'array', 'max:20'],
            'floor.storeys.*' => ['nullable', 'string', 'max:60'],
            'floor.separate_halls' => ['boolean'],
            'floor.backgrounds' => ['nullable', 'array', 'max:20'],
            'floor.backgrounds.*.storey' => ['required', 'integer', 'min:0', 'max:19'],
            'floor.backgrounds.*.path' => ['required', 'string', 'max:200'],
            'floor.backgrounds.*.x' => ['required', 'numeric', 'min:-400', 'max:400'],
            'floor.backgrounds.*.y' => ['required', 'numeric', 'min:-400', 'max:400'],
            'floor.backgrounds.*.width' => ['required', 'numeric', 'min:0.5', 'max:1000'],
            'floor.backgrounds.*.aspect' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'floor.backgrounds.*.opacity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'floor.backgrounds.*.hidden' => ['boolean'],
            'shelves' => ['present', 'array', 'max:1500'],
            'shelves.*.id' => ['nullable', 'integer'],
            'shelves.*.code' => ['required', 'string', 'max:30', 'distinct:ignore_case'],
            'shelves.*.name' => ['nullable', 'string', 'max:255'],
            'shelves.*.kind' => ['required', 'in:'.implode(',', MapItem::KINDS)],
            'shelves.*.storey' => ['nullable', 'integer', 'min:0', 'max:19'],
            'shelves.*.computer_id' => ['nullable', 'integer', 'exists:exam_computers,id', 'distinct'],
            'shelves.*.x' => ['required', 'numeric', 'min:0', 'max:300'],
            'shelves.*.y' => ['required', 'numeric', 'min:0', 'max:300'],
            'shelves.*.rotation' => ['required', 'integer', 'min:-359', 'max:359'],
            'shelves.*.width' => ['required', 'numeric', 'min:0.2', 'max:300'],
            'shelves.*.depth' => ['required', 'numeric', 'min:0.1', 'max:300'],
            'shelves.*.levels' => ['nullable', 'integer', 'min:1', 'max:10'],
            'shelves.*.double_sided' => ['boolean'],
            'shelves.*.locked' => ['boolean'],
            'shelves.*.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'shelves.*.points' => ['nullable', 'required_if:shelves.*.kind,obstacle,hall', 'array', 'min:3', 'max:64'],
            'shelves.*.points.*' => ['array', 'size:2'],
            'shelves.*.points.*.*' => ['numeric', 'min:-300', 'max:300'],
            'shelves.*.height' => ['nullable', 'numeric', 'min:0.1', 'max:6'],
        ], [
            'shelves.*.code.distinct' => 'رمز مكرر: كل عنصر على الخريطة يحتاج رمزاً مختلفاً.',
            'shelves.*.computer_id.distinct' => 'الحاسبة نفسها موضوعة في مكانين على الخريطة.',
            'shelves.*.points.required_if' => 'ارسم الشكل: 3 نقاط على الأقل.',
            'shelves.*.points.min' => 'ارسم الشكل: 3 نقاط على الأقل.',
        ]);

        DB::transaction(function () use ($data) {
            $this->map->saveFloor($data['floor']);
            $keep = [];
            foreach ($data['shelves'] as $row) {
                $fields = collect($row)->only((new MapItem)->getFillable())->all();
                $fields['double_sided'] = (bool) ($row['double_sided'] ?? false);
                $fields['locked'] = (bool) ($row['locked'] ?? false);
                $fields['levels'] = (int) ($row['levels'] ?? 1);
                $fields['storey'] = (int) ($row['storey'] ?? 0);
                $fields['rotation'] = (((int) $row['rotation']) % 360 + 360) % 360;
                $fields['points'] = in_array($row['kind'], MapItem::SHAPED, true) ? array_map(fn ($p) => [round((float) $p[0], 2), round((float) $p[1], 2)], $row['points']) : null;
                $fields['height'] = $row['kind'] === 'obstacle' ? (float) ($row['height'] ?? 1) : null;
                $fields['computer_id'] = $row['kind'] === 'pc' ? ($row['computer_id'] ?? null) : null;
                $item = ! empty($row['id']) ? MapItem::find($row['id']) : null;
                $item ? $item->update($fields) : $item = MapItem::create($fields);
                $keep[] = $item->id;
            }
            MapItem::whereNotIn('id', $keep)->delete();
        });
        ActivityLog::record('exam.hall_map', 'حفظ خريطة القاعات ('.count($data['shelves']).' عنصراً)');

        return back()->with('success', 'حُفظت خريطة القاعات.');
    }

    /** A picture (or the first page of a PDF) of a floor's plan, to draw the halls over. */
    public function background(Request $request): JsonResponse
    {
        abort_unless($this->canEdit($request), 403);
        $request->validate(['plan' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480']]);
        $file = $request->file('plan');
        $disk = Storage::disk('public');
        $name = HallMap::PLANS.'/'.Str::random(16);
        if (strtolower($file->getClientOriginalExtension()) === 'pdf' || $file->getMimeType() === 'application/pdf') {
            $bin = trim((string) Process::run(['sh', '-c', 'command -v pdftoppm'])->output());
            abort_if($bin === '', 422, 'تحويل PDF غير متوفر على هذا الخادم. ارفع صورة (JPG أو PNG) للمخطط.');
            $tmp = sys_get_temp_dir().'/plan-'.Str::random(8);
            $r = Process::timeout(60)->run([$bin, '-png', '-r', '150', '-f', '1', '-l', '1', '-scale-to', '3000', '-singlefile', $file->getRealPath(), $tmp]);
            abort_unless($r->successful() && is_file($tmp.'.png'), 422, 'تعذّرت قراءة ملف PDF.');
            $path = $name.'.png';
            $disk->put($path, file_get_contents($tmp.'.png'));
            @unlink($tmp.'.png');
        } else {
            $path = $file->storeAs(HallMap::PLANS, basename($name).'.'.strtolower($file->extension()), 'public');
        }
        [$w, $h] = @getimagesize($disk->path($path)) ?: [0, 0];
        if (! $w || ! $h) {
            $disk->delete($path);
            abort(422, 'الملف ليس صورة صالحة.');
        }

        return response()->json(['path' => $path, 'url' => $disk->url($path), 'aspect' => round($h / $w, 5)]);
    }

    /** Printable QR stickers for the computers and the walls: scanned to say «أنت هنا». */
    public function labels(Request $request): Response
    {
        abort_unless($this->canEdit($request), 403);
        $storeys = $this->map->floor()['storeys'];

        return Inertia::render('Exams/Monitor/HallMapLabels', [
            'items' => $this->map->items()->whereIn('kind', ['pc', 'qr'])->sortBy(fn ($s) => [$s['kind'] === 'qr' ? 1 : 0, $s['storey'], $s['code']])->values()->map(fn ($s) => [
                'id' => $s['id'], 'kind' => $s['kind'], 'code' => $s['code'], 'name' => $s['name'], 'room' => $s['computer']['room'] ?? null,
                'storey' => count($storeys) > 1 ? ($storeys[$s['storey']] ?? null) : null,
                'url' => route('exams.monitor.halls', ['at' => $s['id']]),
            ]),
        ]);
    }

    /** A hall computer saying it is on: every page of the site sends this every half minute. */
    public function ping(Request $request): JsonResponse
    {
        $pc = HallMap::heard((string) $request->ip(), $request->integer('t') ?: null, $request->userAgent(), $request->user()?->id);

        return response()->json(['computer' => $pc?->label]);
    }
}
