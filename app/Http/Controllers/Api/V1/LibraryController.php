<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\Hold;
use App\Models\Library\LibraryMember;
use App\Models\Library\LibrarySection;
use App\Models\Library\Loan;
use App\Models\Library\Notice;
use App\Models\UserNotification;
use App\Services\Library\HoldService;
use App\Services\Library\LibraryMap;
use App\Services\Library\LoanService;
use App\Support\PublicSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** The library for the mobile apps: the catalogue, a book, holds and «استعاراتي», plus the staff loans list. */
class LibraryController extends Controller
{
    public function __construct(private LoanService $loans, private HoldService $holds, private LibraryMap $map) {}

    /**
     * «خريطة المكتبة» in the apps (2026-11-06, by request): the app shows the same 3D map as the
     * website. A signed-in app gets a short-lived signed path that opens the map signed in as the
     * same user; visitors get the public map. The app puts its own server address in front.
     */
    public function mapLink(Request $request): JsonResponse
    {
        $this->allowGuest($request);
        $user = $request->user('sanctum');
        $params = array_filter(['book' => $request->integer('book') ?: null, 'at' => $request->integer('at') ?: null]);
        $path = $user
            ? URL::temporarySignedRoute('library.map.app', now()->addMinutes(5), ['user' => $user->id] + $params, absolute: false)
            : route('library.map', $params + ['app' => 1], false);

        return response()->json(['path' => $path]);
    }

    /**
     * The map for the apps to keep and open without internet (2026-11-11, by request): the
     * floor, every bookcase, the copies on each, and every book with its place (for search). «version» changes whenever any
     * of it does, so the app replaces its saved copy only when the map was edited.
     */
    public function mapData(Request $request): JsonResponse
    {
        $this->allowGuest($request);
        $floor = $this->map->floor();
        $floor['backgrounds'] = [];
        // Every book with where it stands, so the map's search works without internet too.
        $shelves = \App\Models\Library\LibraryShelf::where('kind', 'shelf')->get();
        $books = Book::withAvailability()->with('copies')->orderBy('title')->get()->map(fn (Book $b) => [
            'id' => $b->id, 'title' => $b->title, 'authors' => $b->authors, 'cover_url' => null,
            'available_count' => (int) ($b->available_count ?? 0), 'copies_count' => (int) ($b->copies_count ?? 0),
            'reference_only' => (bool) $b->reference_only,
            'spots' => $this->map->locate($b, $shelves),
        ])->values();
        $data = [
            'floor' => $floor,
            'shelves' => $this->map->shelves()->values(),
            'stock' => (object) $this->map->stock(),
            'books' => $books,
        ];

        return response()->json(['version' => md5(json_encode($data)), ...$data]);
    }

    /**
     * The map page itself (3D and flat), built as one script and one stylesheet for the apps to
     * keep (resources/js/offline-map). «version» changes with every new build of it.
     */
    public function mapBundle(Request $request): JsonResponse
    {
        $this->allowGuest($request);
        $js = public_path('build/offline-map/map.js');
        $css = public_path('build/offline-map/map.css');
        abort_unless(is_file($js) && is_file($css), 404, 'الخريطة غير متاحة للتنزيل بعد.');

        return response()->json([
            'version' => substr(md5(md5_file($js).md5_file($css)), 0, 16),
            'js' => '/build/offline-map/map.js',
            'css' => '/build/offline-map/map.css',
            'size' => filesize($js) + filesize($css),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->allowGuest($request);
        $q = trim((string) $request->string('q'));
        $section = $request->integer('section') ? LibrarySection::find($request->integer('section')) : null;

        $books = Book::query()
            ->withAvailability()
            ->with('section:id,name')
            ->search($q)
            ->when($section, fn ($w) => $w->whereIn('section_id', $section->descendantIds()))
            ->when($request->boolean('available'), fn ($w) => $w->whereHas('copies', fn ($c) => $c->where('status', BookCopy::AVAILABLE)))
            ->orderBy($request->query('sort') === 'new' ? 'id' : 'title', $request->query('sort') === 'new' ? 'desc' : 'asc')
            ->paginate(30);

        return response()->json([
            'data' => collect($books->items())->map(fn (Book $b) => $this->card($b)),
            'meta' => ['current_page' => $books->currentPage(), 'last_page' => $books->lastPage(), 'total' => $books->total()],
            'sections' => LibrarySection::where('parent_id', $section?->id)->orderBy('sort')->orderBy('name')
                ->withCount('books')->get(['id', 'name', 'cover_path', 'updated_at', 'location'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'books_count' => $s->books_count, 'location' => $s->location, 'cover_url' => $s->cover_url]),
            'current_section' => $section ? ['id' => $section->id, 'name' => $section->name, 'path' => $section->path(), 'parent_id' => $section->parent_id] : null,
        ]);
    }

    public function show(Request $request, Book $book): JsonResponse
    {
        $this->allowGuest($request);
        $user = $request->user('sanctum');
        $book->load(['section', 'copies' => fn ($q) => $q->orderBy('barcode')]);
        $hold = $user ? Hold::open()->where('book_id', $book->id)
            ->whereHas('member', fn ($m) => $m->where('user_id', $user->id))->first(['id', 'status', 'expires_at']) : null;

        return response()->json([
            'book' => [
                ...$this->card($book->loadCount(['copies', 'copies as available_count' => fn ($q) => $q->where('status', BookCopy::AVAILABLE)])),
                'subtitle' => $book->subtitle, 'publisher' => $book->publisher, 'edition' => $book->edition,
                'pages' => $book->pages, 'language' => $book->language, 'isbn' => $book->isbn,
                'description' => $book->description, 'section_path' => $book->section?->path(),
            ],
            'copies' => $book->copies->map(fn ($c) => ['id' => $c->id, 'barcode' => $c->barcode, 'shelf' => $c->shelf, 'status' => $c->status]),
            'my_hold' => $hold ? ['id' => $hold->id, 'status' => $hold->status, 'expires_at' => $hold->expires_at?->toIso8601String()] : null,
            'queue_length' => Hold::where('book_id', $book->id)->where('status', Hold::WAITING)->count(),
            'digital_file_id' => $book->file && $user && $user->can('view', $book->file) ? $book->file->id : null,
            'can_hold' => (bool) $user,
            'contact_enabled' => PublicSite::contact(),
            // Where it is on the library map (first bookcase), for «مكانه على الخريطة».
            'map_spot' => ($spot = $this->map->locate($book)[0] ?? null) ? ['code' => $spot['code'], 'level' => $spot['level']] : null,
        ]);
    }

    public function hold(Request $request, Book $book): JsonResponse
    {
        $hold = $this->holds->place(LibraryMember::forUser($request->user()), $book);

        return response()->json([
            'message' => $hold->status === Hold::READY ? 'تم الحجز. الكتاب جاهز للاستلام من المكتبة.' : 'تم الحجز. سنبلغك عند توفر نسخة.',
            'hold' => ['id' => $hold->id, 'status' => $hold->status, 'expires_at' => $hold->expires_at?->toIso8601String()],
        ]);
    }

    public function cancelHold(Request $request, Hold $hold): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageLibrary() || $hold->member?->user_id === $user->id, 403);
        abort_unless(in_array($hold->status, [Hold::WAITING, Hold::READY], true), 422);
        $this->holds->cancel($hold);

        return response()->json(['message' => 'تم إلغاء الحجز.']);
    }

    /** «استعاراتي»: opening it marks the library notices read. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $member = LibraryMember::where('user_id', $user->id)->first();
        $loans = $member
            ? $member->loans()->with('copy.book:id,title,authors,cover_path,updated_at')->latest('issued_at')->limit(100)->get()
            : collect();

        $notices = $member ? Notice::where('member_id', $member->id)->latest()->limit(20)->get() : collect();
        if ($member) {
            Notice::where('member_id', $member->id)->whereNull('read_at')->update(['read_at' => now()]);
        }
        UserNotification::where('user_id', $user->id)->where('url', route('library.my-loans', [], false))
            ->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json([
            'member' => $member?->only(['id', 'name', 'card_number', 'type']),
            'active' => $loans->whereNull('returned_at')->sortBy('due_at')->values()->map(fn (Loan $l) => $this->loanRow($l)),
            'history' => $loans->whereNotNull('returned_at')->values()->map(fn (Loan $l) => $this->loanRow($l)),
            'holds' => $member ? Hold::open()->where('member_id', $member->id)->with('book:id,title,cover_path,updated_at')->get()
                ->map(fn (Hold $h) => ['id' => $h->id, 'status' => $h->status, 'expires_at' => $h->expires_at?->toIso8601String(),
                    'book' => ['id' => $h->book?->id, 'title' => $h->book?->title, 'cover_url' => $h->book?->cover_url]]) : [],
            'notices' => $notices->map(fn ($n) => ['id' => $n->id, 'kind' => $n->kind, 'message' => $n->message,
                'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601String()]),
            'fines_due' => $member?->finesDue() ?? 0,
        ]);
    }

    public function renew(Request $request, Loan $loan): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canManageLibrary() || $loan->member?->user_id === $user->id, 403);
        $this->loans->renew($loan);

        return response()->json(['message' => 'تم التجديد حتى '.$loan->fresh()->due_at->format('Y-m-d').'.', 'loan' => $this->loanRow($loan->fresh('copy.book'))]);
    }

    // ---- Staff ----

    public function loans(Request $request): JsonResponse
    {
        $filter = $request->string('filter', 'active')->toString();
        $q = trim((string) $request->string('q'));

        $fines = fn ($w) => $w->whereNotNull('returned_at')->where('fine_amount', '>', 0)->whereNull('fine_paid_at')->where('fine_waived', false);
        $loans = Loan::query()
            ->with(['copy.book:id,title,authors,cover_path,updated_at', 'member:id,name,card_number,type'])
            ->when($filter === 'active', fn ($w) => $w->active())
            ->when($filter === 'overdue', fn ($w) => $w->overdue())
            ->when($filter === 'returned', fn ($w) => $w->whereNotNull('returned_at'))
            ->when($filter === 'fines', $fines)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->whereHas('member', fn ($m) => $m->where('name', 'like', "%{$q}%")->orWhere('card_number', $q))
                ->orWhereHas('copy', fn ($c) => $c->where('barcode', $q)->orWhereHas('book', fn ($b) => $b->where('title', 'like', "%{$q}%")))))
            ->orderBy($filter === 'returned' ? 'returned_at' : 'due_at', $filter === 'returned' ? 'desc' : 'asc')
            ->paginate(30);

        return response()->json([
            'data' => collect($loans->items())->map(fn (Loan $l) => [...$this->loanRow($l), 'member' => $l->member?->only(['id', 'name', 'card_number', 'type'])]),
            'meta' => ['current_page' => $loans->currentPage(), 'last_page' => $loans->lastPage(), 'total' => $loans->total()],
            'counts' => [
                'active' => Loan::active()->count(),
                'overdue' => Loan::overdue()->count(),
                'returned' => Loan::whereNotNull('returned_at')->count(),
                'fines' => $fines(Loan::query())->count(),
                'holds' => Hold::open()->count(),
            ],
        ]);
    }

    public function holdsQueue(): JsonResponse
    {
        return response()->json(Hold::open()->with(['book:id,title,cover_path,updated_at', 'member:id,name,card_number', 'copy:id,barcode'])
            ->orderByRaw("case when status = 'ready' then 0 else 1 end")->orderBy('id')->limit(200)->get()
            ->map(fn (Hold $h) => ['id' => $h->id, 'status' => $h->status, 'expires_at' => $h->expires_at?->toIso8601String(),
                'book' => ['id' => $h->book?->id, 'title' => $h->book?->title, 'cover_url' => $h->book?->cover_url],
                'member' => $h->member?->only(['id', 'name', 'card_number']), 'barcode' => $h->copy?->barcode]));
    }

    public function payFine(Loan $loan): JsonResponse
    {
        abort_if($loan->returned_at === null, 422, 'تُستوفى الغرامة بعد إرجاع الكتاب.');
        $loan->update(['fine_paid_at' => now()]);
        ActivityLog::record('library.fine_paid', "استوفى غرامة {$loan->fine_amount} د.ع من {$loan->member?->name}");

        return response()->json(['message' => 'تم تسجيل دفع الغرامة.']);
    }

    public function waiveFine(Loan $loan): JsonResponse
    {
        $loan->update(['fine_waived' => true]);
        ActivityLog::record('library.fine_waived', "أعفى {$loan->member?->name} من غرامة {$loan->fine_amount} د.ع");

        return response()->json(['message' => 'تم الإعفاء من الغرامة.']);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'books' => Book::count(),
            'copies' => BookCopy::count(),
            'available' => BookCopy::where('status', BookCopy::AVAILABLE)->count(),
            'active_loans' => Loan::active()->count(),
            'overdue' => Loan::overdue()->count(),
            'holds_ready' => Hold::where('status', Hold::READY)->count(),
            'members' => LibraryMember::count(),
            'issued_today' => Loan::whereDate('issued_at', today())->count(),
            'returned_today' => Loan::whereDate('returned_at', today())->count(),
        ]);
    }

    private function allowGuest(Request $request): void
    {
        abort_unless($request->user('sanctum') || PublicSite::library(), 401, 'سجّل الدخول لتصفح المكتبة.');
    }

    private function card(Book $b): array
    {
        return [
            'id' => $b->id,
            'title' => $b->title,
            'authors' => $b->authors,
            'year' => $b->year,
            'cover_url' => $b->cover_url,
            'reference_only' => (bool) $b->reference_only,
            'copies_count' => (int) ($b->copies_count ?? 0),
            'available_count' => (int) ($b->available_count ?? 0),
            'section' => $b->relationLoaded('section') && $b->section ? ['id' => $b->section->id, 'name' => $b->section->name] : null,
        ];
    }

    private function loanRow(Loan $l): array
    {
        return [
            'id' => $l->id,
            'barcode' => $l->copy?->barcode,
            'book' => ['id' => $l->copy?->book?->id, 'title' => $l->copy?->book?->title, 'authors' => $l->copy?->book?->authors, 'cover_url' => $l->copy?->book?->cover_url],
            'issued_at' => $l->issued_at?->toIso8601String(),
            'due_at' => $l->due_at?->toIso8601String(),
            'returned_at' => $l->returned_at?->toIso8601String(),
            'renewals' => (int) ($l->renewals ?? 0),
            'is_overdue' => $l->returned_at === null && $l->is_overdue,
            'days_overdue' => $l->days_overdue,
            'fine' => $l->returned_at === null ? $l->currentFine() : (int) $l->fine_amount,
            'fine_paid' => $l->fine_paid_at !== null,
            'fine_waived' => (bool) $l->fine_waived,
        ];
    }
}
