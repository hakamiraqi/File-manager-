<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\Book;
use App\Models\Library\Hold;
use App\Models\Library\LibrarySection;
use App\Services\Library\Citation;
use App\Services\Library\LibraryMap;
use App\Support\PublicSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The catalogue readers browse: sections, covers, availability. */
class CatalogController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (! $request->user() && ! PublicSite::library()) {
            return redirect()->guest(route('login'));
        }
        $q = trim((string) $request->string('q'));
        $sectionId = $request->integer('section') ?: null;
        $section = $sectionId ? LibrarySection::find($sectionId) : null;

        $books = Book::query()
            ->withAvailability()
            ->with('section:id,name')
            ->search($q)
            ->when($section, fn ($query) => $query->whereIn('section_id', $section->descendantIds()))
            ->when($request->boolean('available'), fn ($query) => $query->whereHas('copies', fn ($c) => $c->where('status', 'available')))
            ->orderBy('title')
            ->paginate(24)
            ->withQueryString();
        $books->getCollection()->load('copies:id,book_id,shelf,status,barcode');
        app(LibraryMap::class)->attachSpots($books->getCollection());

        return Inertia::render('Library/Index', [
            'books' => $books,
            'sections' => LibrarySection::options(),
            'currentSection' => $section ? ['id' => $section->id, 'path' => $section->path(), 'description' => $section->description, 'cover_url' => $section->cover_url] : null,
            'childSections' => LibrarySection::where('parent_id', $section?->id)->orderBy('sort')->orderBy('name')
                ->withCount('books')->get(['id', 'name', 'cover_path', 'updated_at', 'location']),
            'filters' => ['q' => $q, 'section' => $sectionId, 'available' => $request->boolean('available')],
        ]);
    }

    public function show(Request $request, Book $book): Response|RedirectResponse
    {
        if (! $request->user() && ! PublicSite::library()) {
            return redirect()->guest(route('login'));
        }
        $book->load(['section', 'copies' => fn ($q) => $q->orderBy('barcode')]);
        $user = $request->user();
        $canManage = $user?->canManageLibrary() ?? false;

        return Inertia::render('Library/Book', [
            'book' => $book,
            'sectionPath' => $book->section?->path(),
            'copies' => $book->copies->map(fn ($c) => [
                'id' => $c->id,
                'barcode' => $c->barcode,
                'shelf' => $c->shelf,
                'status' => $c->status,
                'rfid_tag' => $canManage ? $c->rfid_tag : null,
            ]),
            'contactEnabled' => PublicSite::contact(),
            'spots' => app(LibraryMap::class)->locate($book),
            'myHold' => $user ? Hold::open()->where('book_id', $book->id)
                ->whereHas('member', fn ($m) => $m->where('user_id', $user->id))->first(['id', 'status', 'expires_at']) : null,
            'queueLength' => Hold::where('book_id', $book->id)->where('status', 'waiting')->count(),
            // The digital copy, when it is a file this reader may open.
            'digitalFile' => $book->file && $user && $user->can('view', $book->file)
                ? ['id' => $book->file->id, 'name' => $book->file->name] : null,
        ]);
    }

    /** «استشهاد»: the book as a reference (APA, Vancouver, BibTeX, RIS); ?download=ris|bib gives the file. */
    public function cite(Request $request, Book $book)
    {
        abort_unless($request->user() || $request->user('sanctum') || PublicSite::library(), 403);
        $c = Citation::for($book);
        if ($format = $request->query('download')) {
            abort_unless(in_array($format, ['ris', 'bib'], true), 404);
            $name = Str::slug(Str::ascii($book->title)) ?: 'reference-'.$book->id;

            return response($format === 'ris' ? $c->ris() : $c->bibtex(), 200, [
                'Content-Type' => $format === 'ris' ? 'application/x-research-info-systems; charset=utf-8' : 'application/x-bibtex; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$name.'.'.$format.'"',
            ]);
        }

        return response()->json($c->all());
    }

    public function cover(string $type, int $id): BinaryFileResponse
    {
        $model = $type === 'section' ? LibrarySection::find($id) : Book::withTrashed()->find($id);
        abort_unless($model && $model->cover_path && Storage::disk('local')->exists($model->cover_path), 404);

        return response()->file(Storage::disk('local')->path($model->cover_path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=2592000, immutable',
        ]);
    }
}
