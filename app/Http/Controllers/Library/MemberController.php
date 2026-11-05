<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\LibraryMember;
use App\Services\Library\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Library staff: readers (with or without a site account) and their cards. */
class MemberController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->string('q'));
        $members = LibraryMember::query()
            ->withCount(['loans as active_loans_count' => fn ($l) => $l->whereNull('returned_at')])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")
                ->orWhere('card_number', $q)->orWhere('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Library/Manage/Members', ['members' => $members, 'filters' => ['q' => $q]]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        LibraryMember::create([...$data, 'card_number' => $data['card_number'] ?: LibraryMember::newCardPlaceholder()]);

        return back()->with('success', 'تمت إضافة القارئ.');
    }

    public function update(Request $request, LibraryMember $member): RedirectResponse
    {
        $data = $this->validated($request, $member);
        $member->update([...$data, 'card_number' => $data['card_number'] ?: $member->card_number]);

        return back()->with('success', 'تم حفظ القارئ.');
    }

    public function destroy(LibraryMember $member): RedirectResponse
    {
        abort_if($member->activeLoans()->exists(), 422, 'على القارئ كتب غير مُرجعة.');
        $member->delete();

        return back()->with('success', 'تم حذف القارئ.');
    }

    /** Readers from Excel/CSV: الاسم، رقم البطاقة، النوع (طالب/تدريسي/موظف)، المرحلة، الهاتف، البريد. */
    public function import(Request $request, SpreadsheetReader $reader): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xlsx,csv,txt']]);
        $file = $request->file('file');
        $rows = $reader->read($file->getRealPath(), $file->getClientOriginalExtension());
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows) ?? []);
        $col = fn (array $names) => collect($header)->search(fn ($h) => in_array($h, $names, true));
        $map = [
            'name' => $col(['name', 'الاسم']),
            'card_number' => $col(['card', 'card_number', 'رقم البطاقة', 'البطاقة']),
            'type' => $col(['type', 'النوع']),
            'stage' => $col(['stage', 'المرحلة']),
            'phone' => $col(['phone', 'الهاتف']),
            'email' => $col(['email', 'البريد']),
        ];
        if ($map['name'] === false) {
            return back()->withErrors(['file' => 'لم أجد عمود «الاسم» في الصف الأول.']);
        }

        $types = ['طالب' => 'student', 'تدريسي' => 'teacher', 'موظف' => 'staff', 'student' => 'student', 'teacher' => 'teacher', 'staff' => 'staff'];
        $added = 0;
        foreach ($rows as $row) {
            $get = fn ($k) => $map[$k] === false ? '' : trim((string) ($row[$map[$k]] ?? ''));
            if ($get('name') === '' || ($get('card_number') !== '' && LibraryMember::where('card_number', $get('card_number'))->exists())) {
                continue;
            }
            LibraryMember::create([
                'name' => $get('name'),
                'card_number' => $get('card_number') ?: LibraryMember::newCardPlaceholder(),
                'type' => $types[mb_strtolower($get('type'))] ?? 'student',
                'stage' => $get('stage') ?: null,
                'phone' => $get('phone') ?: null,
                'email' => $get('email') ?: null,
            ]);
            $added++;
        }

        return back()->with('success', "تمت إضافة {$added} قارئاً.");
    }

    /** Printable reader cards (name + card barcode). */
    public function cards(Request $request): Response
    {
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        $members = LibraryMember::when($ids, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->limit(500)
            ->get(['id', 'name', 'card_number', 'type', 'stage']);

        return Inertia::render('Library/Cards', ['members' => $members]);
    }

    private function validated(Request $request, ?LibraryMember $member = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'card_number' => ['nullable', 'string', 'max:64', Rule::unique('library_members', 'card_number')->ignore($member?->id)],
            'type' => ['required', Rule::in(LibraryMember::TYPES)],
            'stage' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]) + ['card_number' => null];
    }
}
