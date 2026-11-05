<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\BookCopy;
use App\Models\Library\LibraryMember;
use App\Models\User;
use App\Services\Library\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The lending desk. One input takes every scan (barcode reader, RFID reader or typing):
 * a reader card selects the reader, a lent copy is received, an available copy is lent
 * to the selected reader.
 */
class CounterController extends Controller
{
    public function __construct(private LoanService $loans) {}

    public function page(): Response
    {
        return Inertia::render('Library/Counter');
    }

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:128'],
            'member_id' => ['nullable', 'integer', 'exists:library_members,id'],
            'condition' => ['nullable', 'in:good,damaged'],
            'mode' => ['nullable', 'in:auto,return'],
        ]);
        $code = trim($data['code']);

        if ($member = LibraryMember::where('card_number', $code)->first()) {
            return response()->json(['kind' => 'member', 'member' => $this->summary($member)]);
        }

        $copy = BookCopy::findByCode($code);
        if (! $copy) {
            return response()->json(['message' => "الرمز \"{$code}\" غير معروف (لا نسخة ولا بطاقة قارئ)."], 422);
        }

        if ($copy->status === BookCopy::LOANED) {
            $loan = $this->loans->receive($copy, $request->user(), $data['condition'] ?? 'good');

            return response()->json([
                'kind' => 'returned',
                'loan' => $loan,
                'held_for' => $copy->fresh()->status === BookCopy::ON_HOLD ? $this->heldFor($copy) : null,
                'member' => $this->summary($loan->member->fresh()),
            ]);
        }

        if (($data['mode'] ?? 'auto') === 'return') {
            return response()->json(['message' => 'هذه النسخة ليست مُعارة.'], 422);
        }

        if (empty($data['member_id'])) {
            return response()->json(['message' => 'امسح بطاقة القارئ أو اختره أولاً، ثم امسح الكتاب.'], 422);
        }

        $member = LibraryMember::findOrFail($data['member_id']);
        $loan = $this->loans->issue($member, $copy, $request->user());

        return response()->json(['kind' => 'issued', 'loan' => $loan, 'member' => $this->summary($member->fresh())]);
    }

    /** Readers and site users matching a name, card number, e-mail or phone. */
    public function members(Request $request): JsonResponse
    {
        $q = trim((string) $request->string('q'));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $members = LibraryMember::where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('card_number', $q)
            ->orWhere('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
            ->limit(10)->get()
            ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'card_number' => $m->card_number, 'type' => $m->type, 'user_id' => $m->user_id]);

        // Site users who never borrowed yet show up too (a reader record is made on pick).
        $users = User::whereDoesntHave('libraryMember')
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"))
            ->limit(10)->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->name, 'email' => $u->email]);

        return response()->json([...$members, ...$users]);
    }

    public function member(Request $request): JsonResponse
    {
        $data = $request->validate(['member_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer']]);
        $member = ! empty($data['member_id'])
            ? LibraryMember::findOrFail($data['member_id'])
            : LibraryMember::forUser(User::findOrFail($data['user_id']));

        return response()->json(['member' => $this->summary($member)]);
    }

    private function summary(LibraryMember $member): array
    {
        $loans = $member->activeLoans()->with('copy.book:id,title,cover_path,updated_at')->orderBy('due_at')->get();

        return [
            'id' => $member->id,
            'name' => $member->name,
            'card_number' => $member->card_number,
            'type' => $member->type,
            'stage' => $member->stage,
            'active' => $member->active,
            'fines_due' => $member->finesDue(),
            'loans' => $loans->map(fn ($l) => [
                'id' => $l->id,
                'barcode' => $l->copy?->barcode,
                'title' => $l->copy?->book?->title,
                'due_at' => $l->due_at->toDateString(),
                'is_overdue' => $l->is_overdue,
                'days_overdue' => $l->days_overdue,
                'fine' => $l->currentFine(),
            ]),
        ];
    }

    protected function heldFor(BookCopy $copy): ?array
    {
        $hold = \App\Models\Library\Hold::where('copy_id', $copy->id)->where('status', \App\Models\Library\Hold::READY)->with('member:id,name,card_number')->first();

        return $hold ? ['name' => $hold->member->name, 'card_number' => $hold->member->card_number] : null;
    }
}
