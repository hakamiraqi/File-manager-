<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\Exams\Computer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The hall's computers, each known by its fixed address. Opened from a hall computer, the page
 * shows that computer's address and registers it with one click.
 */
class ComputerController extends Controller
{
    public function index(Request $request): Response
    {
        $ip = (string) $request->ip();

        return Inertia::render('Exams/Monitor/Computers', [
            'computers' => Computer::orderBy('room')->orderBy('label')->get()->sortBy([['room', 'asc'], ['label', SORT_NATURAL]])->values(),
            'here' => ['ip' => $ip, 'computer' => Computer::where('ip', $ip)->first()],
            'rooms' => Computer::whereNotNull('room')->distinct()->orderBy('room')->pluck('room'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        // Several at once: «PC-01 192.168.1.11» on each line.
        if (filled($request->input('bulk'))) {
            $added = 0;
            foreach (preg_split('/\R/u', (string) $request->input('bulk')) as $line) {
                if (preg_match('/^\s*(\S+)\s+(\S+)\s*$/u', $line, $m) && filter_var($m[2], FILTER_VALIDATE_IP)) {
                    Computer::updateOrCreate(['ip' => $m[2]], ['label' => $m[1], 'room' => $data['room'] ?? null]);
                    $added++;
                }
            }

            return back()->with('success', "سُجّلت {$added} حاسبة.");
        }
        Computer::updateOrCreate(['ip' => $data['ip']], $data);

        return back()->with('success', 'سُجّلت الحاسبة.');
    }

    /**
     * Lays a room out on the hall map: its computers, by name, in rows of the given length
     * (PC-01…PC-06 the first row, PC-07… the second). Single seats can be moved after.
     */
    public function arrange(Request $request): RedirectResponse
    {
        $data = $request->validate(['room' => ['nullable', 'string', 'max:100'], 'columns' => ['required', 'integer', 'min:1', 'max:40']]);
        $pcs = Computer::when(filled($data['room'] ?? null), fn ($q) => $q->where('room', $data['room']), fn ($q) => $q->whereNull('room'))
            ->get()->sortBy('label', SORT_NATURAL)->values();
        foreach ($pcs as $i => $pc) {
            $pc->update(['seat_row' => intdiv($i, $data['columns']) + 1, 'seat_col' => $i % $data['columns'] + 1]);
        }

        return back()->with('success', 'رُتّبت '.$pcs->count().' حاسبة على الخريطة.');
    }

    public function update(Request $request, Computer $computer): RedirectResponse
    {
        $computer->update($this->validated($request, $computer));

        return back()->with('success', 'تم الحفظ.');
    }

    public function destroy(Computer $computer): RedirectResponse
    {
        $computer->delete();

        return back()->with('success', 'حُذفت الحاسبة.');
    }

    private function validated(Request $request, ?Computer $computer = null): array
    {
        $bulk = filled($request->input('bulk'));

        return $request->validate([
            'label' => [$bulk ? 'nullable' : 'required', 'string', 'max:50'],
            'room' => ['nullable', 'string', 'max:100'],
            'seat_row' => ['nullable', 'integer', 'min:1', 'max:60'],
            'seat_col' => ['nullable', 'integer', 'min:1', 'max:60'],
            'ip' => [$bulk ? 'nullable' : 'required', 'ip', $computer ? Rule::unique('exam_computers', 'ip')->ignore($computer->id) : 'nullable'],
        ], ['ip.unique' => 'هذا العنوان لحاسبة أخرى.', 'ip.ip' => 'عنوان IP غير صالح.']);
    }
}
