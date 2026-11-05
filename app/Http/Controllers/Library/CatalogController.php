<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\Book;
use App\Models\Library\LibrarySection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The catalogue readers browse: sections, covers, availability. */
class CatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->string('q'));
        $sectionId = $request->integer('section') ?: null;
        $section = $sectionId ? LibrarySection::find($sectionId) : null;

        $books = Book::query()
            ->withAvailability()
            ->with('section:id,name')
            ->search($q)
            ->when($section, fn ($query) => $query->whereIn('section_id', $section->descendantIds()))
            ->orderBy('title')
            ->paginate(24)
            ->withQueryString();

        return Inertia::render('Library/Index', [
            'books' => $books,
            'sections' => LibrarySection::options(),
            'currentSection' => $section ? ['id' => $section->id, 'path' => $section->path(), 'description' => $section->description, 'cover_url' => $section->cover_url] : null,
            'childSections' => LibrarySection::where('parent_id', $section?->id)->orderBy('sort')->orderBy('name')
                ->withCount('books')->get(['id', 'name', 'cover_path', 'updated_at', 'location']),
            'filters' => ['q' => $q, 'section' => $sectionId],
        ]);
    }

    public function show(Request $request, Book $book): Response
    {
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
            // The digital copy, when it is a file this reader may open.
            'digitalFile' => $book->file && $user && $user->can('view', $book->file)
                ? ['id' => $book->file->id, 'name' => $book->file->name] : null,
        ]);
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
