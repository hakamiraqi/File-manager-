<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\LibrarySection;
use App\Services\Library\CoverStore;
use App\Services\Library\IsbnLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Library staff: books, their copies, ISBN lookup and barcode labels. */
class BookController extends Controller
{
    public function __construct(private CoverStore $covers) {}

    public function index(Request $request): Response
    {
        $q = trim((string) $request->string('q'));
        $sectionId = $request->integer('section') ?: null;
        $section = $sectionId ? LibrarySection::find($sectionId) : null;

        $books = Book::query()
            ->withAvailability()
            ->with(['section:id,name', 'copies' => fn ($c) => $c->orderBy('barcode'), 'file:id,name'])
            ->search($q)
            ->when($section, fn ($query) => $query->whereIn('section_id', $section->descendantIds()))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Library/Manage/Books', [
            'books' => $books,
            'sections' => LibrarySection::options(),
            'filters' => ['q' => $q, 'section' => $sectionId],
            'statuses' => BookCopy::STATUSES,
            // Bookcase codes from «ترتيب الرفوف», suggested in every «الرف» box.
            'shelfCodes' => \App\Models\Library\LibraryShelf::where('kind', 'shelf')->orderBy('code')->pluck('code'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $book = Book::create([...$this->bookFields($data), 'created_by' => $request->user()->id]);
        $this->applyCover($request, $book, $data);
        $this->createCopies($book, (int) ($data['copies'] ?? 1), $data['shelf'] ?? null);

        ActivityLog::record('library.book_created', "أضاف الكتاب \"{$book->title}\" إلى المكتبة", $book);

        return back()->with('success', 'تمت إضافة الكتاب.');
    }

    public function update(Request $request, Book $book): RedirectResponse
    {
        $data = $this->validated($request, $book);

        $book->update($this->bookFields($data));
        $this->applyCover($request, $book, $data);

        ActivityLog::record('library.book_updated', "حدّث الكتاب \"{$book->title}\"", $book);

        return back()->with('success', 'تم حفظ الكتاب.');
    }

    public function destroy(Book $book): RedirectResponse
    {
        abort_if($book->copies()->where('status', BookCopy::LOANED)->exists(), 422, 'لا يمكن حذف كتاب له نسخ مُعارة حالياً.');

        $book->delete();
        ActivityLog::record('library.book_deleted', "حذف الكتاب \"{$book->title}\"");

        return back()->with('success', 'تم حذف الكتاب.');
    }

    public function isbn(string $isbn, IsbnLookup $lookup): JsonResponse
    {
        $found = $lookup->find($isbn);

        return $found ? response()->json($found) : response()->json(['message' => 'لم يُعثر على بيانات لهذا الرقم (أو لا يوجد إنترنت).'], 404);
    }

    public function addCopies(Request $request, Book $book): RedirectResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:100'],
            'shelf' => ['nullable', 'string', 'max:255'],
        ]);

        $this->createCopies($book, $data['count'], $data['shelf'] ?? null);

        return back()->with('success', 'تمت إضافة النسخ.');
    }

    public function updateCopy(Request $request, BookCopy $copy): RedirectResponse
    {
        $data = $request->validate([
            'barcode' => ['sometimes', 'string', 'max:64', Rule::unique('book_copies', 'barcode')->ignore($copy->id)],
            'rfid_tag' => ['sometimes', 'nullable', 'string', 'max:128', Rule::unique('book_copies', 'rfid_tag')->ignore($copy->id)],
            'shelf' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([BookCopy::AVAILABLE, BookCopy::DAMAGED, BookCopy::LOST])],
            'condition' => ['sometimes', 'nullable', 'string', 'max:20'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [
            'rfid_tag.unique' => 'رمز RFID هذا مربوط بنسخة أخرى.',
            'barcode.unique' => 'هذا الباركود مستخدم لنسخة أخرى.',
        ]);

        // A lent or reserved copy changes status only through the counter.
        if (isset($data['status']) && in_array($copy->status, [BookCopy::LOANED, BookCopy::ON_HOLD], true)) {
            unset($data['status']);
        }

        $copy->update($data);

        return back()->with('success', 'تم حفظ النسخة.');
    }

    public function destroyCopy(BookCopy $copy): RedirectResponse
    {
        abort_if($copy->status === BookCopy::LOANED, 422, 'النسخة مُعارة حالياً.');
        $copy->delete();

        return back()->with('success', 'تم حذف النسخة.');
    }

    /** Printable barcode labels for chosen copies, or all copies of the chosen books. */
    public function labels(Request $request): Response
    {
        $copyIds = array_filter(array_map('intval', (array) $request->input('copies', [])));
        $bookIds = array_filter(array_map('intval', (array) $request->input('books', [])));

        $copies = BookCopy::with('book:id,title,authors')
            ->when($copyIds, fn ($q) => $q->whereIn('id', $copyIds))
            ->when(! $copyIds && $bookIds, fn ($q) => $q->whereIn('book_id', $bookIds))
            ->when(! $copyIds && ! $bookIds, fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('barcode')
            ->limit(500)
            ->get()
            ->map(fn ($c) => ['barcode' => $c->barcode, 'shelf' => $c->shelf, 'title' => $c->book?->title, 'authors' => $c->book?->authors]);

        return Inertia::render('Library/Labels', ['labels' => $copies]);
    }

    private function validated(Request $request, ?Book $book = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'authors' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:20'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'string', 'max:10'],
            'language' => ['nullable', 'string', 'max:20'],
            'edition' => ['nullable', 'string', 'max:50'],
            'pages' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'section_id' => ['nullable', 'integer', 'exists:library_sections,id'],
            'file_id' => ['nullable', 'integer', 'exists:files,id'],
            'reference_only' => ['boolean'],
            'cover' => ['nullable', 'image', 'max:8192'],
            'cover_url' => ['nullable', 'url', 'max:500'],
            'remove_cover' => ['boolean'],
            'copies' => [$book ? 'nullable' : 'required', 'integer', 'min:0', 'max:100'],
            'shelf' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function bookFields(array $data): array
    {
        if (! empty($data['isbn'])) {
            $data['isbn'] = preg_replace('/[^0-9Xx]/', '', $data['isbn']);
        }
        if (! empty($data['file_id']) && ! request()->user()->can('view', File::find($data['file_id']))) {
            $data['file_id'] = null;
        }

        return array_intersect_key($data, array_flip([
            'title', 'subtitle', 'authors', 'isbn', 'publisher', 'year', 'language', 'edition', 'pages',
            'description', 'section_id', 'file_id', 'reference_only',
        ]));
    }

    private function applyCover(Request $request, Book $book, array $data): void
    {
        $new = null;
        if ($request->hasFile('cover')) {
            $new = $this->covers->fromUpload($request->file('cover'));
        } elseif (! empty($data['cover_url'])) {
            $new = $this->covers->fromUrl($data['cover_url']);
        }

        if ($new || ! empty($data['remove_cover'])) {
            $this->covers->delete($book->cover_path);
            $book->forceFill(['cover_path' => $new])->save();
        }
    }

    private function createCopies(Book $book, int $count, ?string $shelf): void
    {
        for ($i = 0; $i < $count; $i++) {
            $book->copies()->create(['barcode' => BookCopy::newBarcodePlaceholder(), 'shelf' => $shelf]);
        }
    }
}
