<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\Book;
use App\Models\Library\LibrarySection;
use App\Models\Library\LibraryShelf;
use App\Models\User;
use App\Services\Library\LibraryMap;
use App\Services\Ocr\OcrEngine;
use App\Support\PublicSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «خريطة المكتبة» (2026-11-06, by request): a 3D model of the reading room. Searching a book
 * draws the walk from the entrance to its bookcase, Waze style; staff lay the room out on a
 * simple top-down editor.
 */
class MapController extends Controller
{
    public function __construct(private LibraryMap $map) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->user() && ! PublicSite::library()) {
            return redirect()->guest(route('login'));
        }
        $book = $request->integer('book') ? Book::withAvailability()->with('copies')->find($request->integer('book')) : null;

        return Inertia::render('Library/Map', [
            'floor' => $this->map->floor(),
            'shelves' => $this->map->shelves(),
            'stock' => (object) $this->map->stock(),
            'target' => $book ? $this->bookPayload($book) : null,
            'canEdit' => (bool) $request->user()?->canManageLibrary(),
            // ?at=12 comes from the QR sticker on bookcase 12: «أنت هنا».
            'startAt' => $request->integer('at') ?: null,
            // Inside the apps: the map alone, full screen, without the site's menus.
            'embedded' => $request->boolean('app') || (bool) $request->session()->get('library_app'),
        ]);
    }

    /** The apps' way in: a signed link from the API signs the web view in as the app's user. */
    public function app(Request $request): RedirectResponse
    {
        abort_unless($request->hasValidSignature(false), 403);
        $user = User::find($request->integer('user'));
        abort_unless($user && $user->is_active, 403);
        if (Auth::id() !== $user->id) {
            Auth::login($user);
            $request->session()->regenerate();
        }
        $request->session()->put('library_app', true);

        return redirect()->route('library.map', array_filter(['book' => $request->integer('book') ?: null, 'at' => $request->integer('at') ?: null, 'app' => 1]));
    }

    /** The search box on the map: books with where they are. */
    public function search(Request $request): JsonResponse
    {
        abort_unless($request->user() || PublicSite::library(), 403);
        $q = trim((string) $request->string('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['books' => []]);
        }
        $books = Book::withAvailability()->with('copies')->search($q)->orderBy('title')->limit(8)->get();

        return response()->json(['books' => $books->map(fn ($b) => $this->bookPayload($b))->values()]);
    }

    /** «كتب هذا الرف»: covers and copies of the books on one bookcase. */
    public function shelfBooks(Request $request, LibraryShelf $shelf): JsonResponse
    {
        abort_unless($request->user() || PublicSite::library(), 403);
        abort_unless($shelf->kind === 'shelf', 404);

        return response()->json(['books' => $this->map->booksOn($shelf)]);
    }

    public function edit(): Response
    {
        return Inertia::render('Library/Manage/Map', [
            'floor' => $this->map->floor(),
            'shelves' => $this->map->shelves(),
            'sections' => LibrarySection::options(),
        ]);
    }

    /**
     * «مخطط البناية»: an image or the first page of a PDF of a floor plan, to draw the halls
     * over. Saved with the map; until then it is only a file.
     */
    public function background(Request $request): JsonResponse
    {
        $request->validate(['plan' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480']]);
        $file = $request->file('plan');
        $disk = Storage::disk('public');
        $name = LibraryMap::PLANS.'/'.Str::random(16);

        if (strtolower($file->getClientOriginalExtension()) === 'pdf' || $file->getMimeType() === 'application/pdf') {
            $bin = app(OcrEngine::class)->pdftoppm();
            abort_if($bin === null, 422, 'تحويل PDF غير متوفر على هذا الخادم. ارفع صورة (JPG أو PNG) للمخطط.');
            $tmp = sys_get_temp_dir().'/plan-'.Str::random(8);
            $r = Process::timeout(60)->run([$bin, '-png', '-r', '150', '-f', '1', '-l', '1', '-scale-to', '3000', '-singlefile', $file->getRealPath(), $tmp]);
            abort_unless($r->successful() && is_file($tmp.'.png'), 422, 'تعذّرت قراءة ملف PDF.');
            $path = $name.'.png';
            $disk->put($path, file_get_contents($tmp.'.png'));
            @unlink($tmp.'.png');
        } else {
            $path = $file->storeAs(LibraryMap::PLANS, basename($name).'.'.strtolower($file->extension()), 'public');
        }
        [$w, $h] = @getimagesize($disk->path($path)) ?: [0, 0];
        if (! $w || ! $h) {
            $disk->delete($path);
            abort(422, 'الملف ليس صورة صالحة.');
        }

        return response()->json(['path' => $path, 'url' => $disk->url($path), 'aspect' => round($h / $w, 5)]);
    }

    /** «فحص الخريطة»: books with no place on the saved map. */
    public function unplaced(): JsonResponse
    {
        return response()->json($this->map->unplaced());
    }

    /** Printable QR stickers: one per bookcase and the wall stickers, scanned to say «أنت هنا». */
    public function labels(): Response
    {
        $storeys = $this->map->floor()['storeys'];

        return Inertia::render('Library/Manage/MapLabels', [
            'shelves' => $this->map->shelves()->whereIn('kind', ['shelf', 'qr'])->sortBy(fn ($s) => [$s['kind'] === 'qr' ? 1 : 0, $s['storey'], $s['code']])->values()->map(fn ($s) => [
                'id' => $s['id'], 'kind' => $s['kind'], 'code' => $s['code'], 'name' => $s['name'], 'section' => $s['section'], 'color' => $s['color'], 'section_id' => $s['section_id'],
                'storey' => count($storeys) > 1 ? ($storeys[$s['storey']] ?? null) : null,
                'url' => route('library.map', ['at' => $s['id']]),
            ]),
            'mapUrl' => route('library.map'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'floor.width' => ['required', 'numeric', 'min:4', 'max:200'],
            'floor.depth' => ['required', 'numeric', 'min:4', 'max:200'],
            'floor.name' => ['nullable', 'string', 'max:100'],
            'floor.entrance.x' => ['required', 'numeric', 'min:0', 'max:200'],
            'floor.entrance.y' => ['required', 'numeric', 'min:0', 'max:200'],
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
            'shelves' => ['present', 'array', 'max:500'],
            'shelves.*.id' => ['nullable', 'integer'],
            'shelves.*.code' => ['required', 'string', 'max:30', 'distinct:ignore_case'],
            'shelves.*.name' => ['nullable', 'string', 'max:255'],
            'shelves.*.kind' => ['required', 'in:'.implode(',', LibraryShelf::KINDS)],
            'shelves.*.storey' => ['nullable', 'integer', 'min:0', 'max:19'],
            'shelves.*.section_id' => ['nullable', 'integer', 'exists:library_sections,id'],
            'shelves.*.x' => ['required', 'numeric', 'min:0', 'max:200'],
            'shelves.*.y' => ['required', 'numeric', 'min:0', 'max:200'],
            // Any angle (2026-11-06): halls and rooms can face any direction.
            'shelves.*.rotation' => ['required', 'integer', 'min:-359', 'max:359'],
            'shelves.*.width' => ['required', 'numeric', 'min:0.3', 'max:200'],
            'shelves.*.depth' => ['required', 'numeric', 'min:0.1', 'max:200'],
            'shelves.*.levels' => ['required', 'integer', 'min:1', 'max:10'],
            'shelves.*.double_sided' => ['boolean'],
            'shelves.*.locked' => ['boolean'],
            'shelves.*.color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // An obstacle's or a hall's outline: 3 to 64 corners, metres from its centre.
            'shelves.*.points' => ['nullable', 'required_if:shelves.*.kind,obstacle,hall', 'array', 'min:3', 'max:64'],
            'shelves.*.points.*' => ['array', 'size:2'],
            'shelves.*.points.*.*' => ['numeric', 'min:-200', 'max:200'],
            'shelves.*.height' => ['nullable', 'numeric', 'min:0.1', 'max:6'],
        ], [
            'shelves.*.code.distinct' => 'رمز الرف مكرر: كل رف يحتاج رمزاً مختلفاً.',
            'shelves.*.points.required_if' => 'ارسم الشكل: 3 نقاط على الأقل.',
            'shelves.*.points.min' => 'ارسم الشكل: 3 نقاط على الأقل.',
        ]);

        DB::transaction(function () use ($data) {
            $this->map->saveFloor($data['floor']);
            $keep = [];
            foreach ($data['shelves'] as $row) {
                $fields = collect($row)->only((new LibraryShelf)->getFillable())->all();
                $fields['double_sided'] = (bool) ($row['double_sided'] ?? false);
                $fields['locked'] = (bool) ($row['locked'] ?? false);
                $obstacle = $row['kind'] === 'obstacle';
                $fields['storey'] = (int) ($row['storey'] ?? 0);
                $fields['rotation'] = (((int) $row['rotation']) % 360 + 360) % 360;
                $fields['points'] = in_array($row['kind'], LibraryShelf::SHAPED, true) ? array_map(fn ($p) => [round((float) $p[0], 2), round((float) $p[1], 2)], $row['points']) : null;
                $fields['height'] = $obstacle ? (float) ($row['height'] ?? 1) : null;
                $shelf = ! empty($row['id']) ? LibraryShelf::find($row['id']) : null;
                $shelf ? $shelf->update($fields) : $shelf = LibraryShelf::create($fields);
                $keep[] = $shelf->id;
            }
            LibraryShelf::whereNotIn('id', $keep)->delete();
        });

        return back()->with('success', 'تم حفظ خريطة المكتبة.');
    }

    private function bookPayload(Book $book): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title,
            'authors' => $book->authors,
            'cover_url' => $book->cover_url,
            'available_count' => (int) ($book->available_count ?? 0),
            'copies_count' => (int) ($book->copies_count ?? 0),
            'reference_only' => $book->reference_only,
            'spots' => $this->map->locate($book),
        ];
    }
}
