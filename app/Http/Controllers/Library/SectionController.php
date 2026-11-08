<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\LibrarySection;
use App\Services\Library\CoverStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Library staff: sections and sub-sections (shelves / folders) of the catalogue. */
class SectionController extends Controller
{
    public function __construct(private CoverStore $covers) {}

    public function index(): Response
    {
        $counts = LibrarySection::withCount('books')->pluck('books_count', 'id');
        $sections = collect(LibrarySection::options())->map(function ($s) use ($counts) {
            $model = LibrarySection::find($s['id']);

            return [...$s, 'books_count' => $counts[$s['id']] ?? 0, 'description' => $model->description,
                'location' => $model->location, 'sort' => $model->sort, 'cover_url' => $model->cover_url];
        });

        return Inertia::render('Library/Manage/Sections', ['sections' => $sections->values()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $section = LibrarySection::create($data);
        $this->applyCover($request, $section);

        return back()->with('success', 'تمت إضافة القسم.');
    }

    public function update(Request $request, LibrarySection $section): RedirectResponse
    {
        $data = $this->validated($request);
        if (($data['parent_id'] ?? null) && in_array((int) $data['parent_id'], $section->descendantIds(), true)) {
            return back()->withErrors(['parent_id' => 'لا يمكن وضع القسم داخل نفسه.']);
        }

        $section->update($data);
        $this->applyCover($request, $section);

        return back()->with('success', 'تم حفظ القسم.');
    }

    public function destroy(LibrarySection $section): RedirectResponse
    {
        // Books and sub-sections move up to the parent section.
        $section->books()->update(['section_id' => $section->parent_id]);
        $section->children()->update(['parent_id' => $section->parent_id]);
        $this->covers->delete($section->cover_path);
        $section->delete();

        return back()->with('success', 'تم حذف القسم ونُقلت كتبه إلى القسم الأعلى.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:library_sections,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'cover' => ['nullable', 'image', 'max:8192'],
        ]) + ['sort' => 0];
    }

    private function applyCover(Request $request, LibrarySection $section): void
    {
        if ($request->hasFile('cover')) {
            $this->covers->delete($section->cover_path);
            $section->forceFill(['cover_path' => $this->covers->fromUpload($request->file('cover'))])->save();
        } elseif ($request->boolean('remove_cover')) {
            $this->covers->delete($section->cover_path);
            $section->forceFill(['cover_path' => null])->save();
        }
    }
}
