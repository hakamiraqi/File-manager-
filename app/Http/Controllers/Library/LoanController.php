<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Library\LibraryMember;
use App\Models\Library\Loan;
use App\Services\Library\LoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Staff: every loan, overdue list, fines. Readers: "استعاراتي". */
class LoanController extends Controller
{
    public function __construct(private LoanService $loans) {}

    public function index(Request $request): Response
    {
        $filter = $request->string('filter', 'active')->toString();
        $q = trim((string) $request->string('q'));

        $loans = Loan::query()
            ->with(['copy.book:id,title', 'member:id,name,card_number,type'])
            ->when($filter === 'active', fn ($w) => $w->active())
            ->when($filter === 'overdue', fn ($w) => $w->overdue())
            ->when($filter === 'returned', fn ($w) => $w->whereNotNull('returned_at'))
            ->when($filter === 'fines', fn ($w) => $w->whereNotNull('returned_at')->where('fine_amount', '>', 0)->whereNull('fine_paid_at')->where('fine_waived', false))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->whereHas('member', fn ($m) => $m->where('name', 'like', "%{$q}%")->orWhere('card_number', $q))
                ->orWhereHas('copy', fn ($c) => $c->where('barcode', $q)->orWhereHas('book', fn ($b) => $b->where('title', 'like', "%{$q}%")))))
            ->orderBy($filter === 'returned' ? 'returned_at' : 'due_at', $filter === 'returned' ? 'desc' : 'asc')
            ->paginate(30)
            ->withQueryString();

        $loans->getCollection()->each(fn (Loan $l) => $l->setAttribute('fine_now', $l->currentFine()));

        $holds = $filter === 'holds'
            ? \App\Models\Library\Hold::open()->with(['book:id,title', 'member:id,name,card_number', 'copy:id,barcode'])
                ->orderByRaw("case when status = 'ready' then 0 else 1 end")->orderBy('id')->limit(200)->get()
            : [];

        return Inertia::render('Library/Manage/Loans', [
            'loans' => $loans,
            'holds' => $holds,
            'filters' => ['filter' => $filter, 'q' => $q],
            'counts' => [
                'active' => Loan::active()->count(),
                'overdue' => Loan::overdue()->count(),
                'holds' => \App\Models\Library\Hold::open()->count(),
                'fines' => Loan::whereNotNull('returned_at')->where('fine_amount', '>', 0)->whereNull('fine_paid_at')->where('fine_waived', false)->count(),
            ],
        ]);
    }

    public function renew(Request $request, Loan $loan): RedirectResponse
    {
        $this->authorizeLoan($request, $loan);
        $this->loans->renew($loan);

        return back()->with('success', 'تم التجديد حتى '.$loan->fresh()->due_at->format('Y-m-d').'.');
    }

    public function payFine(Loan $loan): RedirectResponse
    {
        abort_if($loan->returned_at === null, 422, 'تُستوفى الغرامة بعد إرجاع الكتاب.');
        $loan->update(['fine_paid_at' => now()]);
        ActivityLog::record('library.fine_paid', "استوفى غرامة {$loan->fine_amount} د.ع من {$loan->member?->name}");

        return back()->with('success', 'تم تسجيل دفع الغرامة.');
    }

    public function waiveFine(Loan $loan): RedirectResponse
    {
        $loan->update(['fine_waived' => true]);
        ActivityLog::record('library.fine_waived', "أعفى {$loan->member?->name} من غرامة {$loan->fine_amount} د.ع");

        return back()->with('success', 'تم الإعفاء من الغرامة.');
    }

    public function mine(Request $request): Response
    {
        $member = LibraryMember::where('user_id', $request->user()->id)->first();
        $loans = $member
            ? $member->loans()->with('copy.book:id,title,authors,cover_path,updated_at')->latest('issued_at')->limit(100)->get()
            : collect();
        $loans->each(fn (Loan $l) => $l->setAttribute('fine_now', $l->currentFine()));

        return Inertia::render('Library/MyLoans', [
            'member' => $member?->only(['id', 'name', 'card_number', 'type']),
            'holds' => $member ? \App\Models\Library\Hold::open()->where('member_id', $member->id)->with('book:id,title,cover_path,updated_at')->get() : [],
            'notices' => $member ? \App\Models\Library\Notice::where('member_id', $member->id)->latest()->limit(20)->get() : [],
            'active' => $loans->whereNull('returned_at')->sortBy('due_at')->values(),
            'history' => $loans->whereNotNull('returned_at')->values(),
            'finesDue' => $member?->finesDue() ?? 0,
        ]);
    }

    public function readNotices(Request $request): RedirectResponse
    {
        $member = LibraryMember::where('user_id', $request->user()->id)->first();
        if ($member) {
            \App\Models\Library\Notice::where('member_id', $member->id)->whereNull('read_at')->update(['read_at' => now()]);
        }

        return back();
    }

    /** Staff renew any loan; a reader only their own. */
    private function authorizeLoan(Request $request, Loan $loan): void
    {
        $user = $request->user();
        abort_unless($user->canManageLibrary() || $loan->member?->user_id === $user->id, 403);
    }
}
