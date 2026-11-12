<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use App\Models\RolePermission;
use App\Models\Library\Book;
use App\Models\Setting;
use App\Services\Theses\ThesisCatalog;
use App\Support\PublicSite;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Unauthenticated browsing of the folders an admin has flagged "public",
 * gated by the global guest_access_enabled toggle and the guest row of the
 * role permission matrix (view/download only, mirroring VFM's
 * view_enable_guest / download_enable_guest split).
 */
class GuestBrowseController extends Controller
{
    /** The visitors' home page: what each switched-on section offers, newest first. */
    public function home(Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        if (! PublicSite::enabled()) {
            return redirect()->route('login');
        }

        $books = PublicSite::library() && Schema::hasTable('books');
        $theses = PublicSite::theses();
        $catalog = app(ThesisCatalog::class);
        $thesisIds = $theses ? $catalog->visibleIds(null) : [];

        return Inertia::render('Guest/Home', [
            'stats' => [
                'books' => $books ? Book::count() : null,
                'copies_available' => $books ? \App\Models\Library\BookCopy::where('status', 'available')->count() : null,
                'theses' => $theses ? count($thesisIds) : null,
                'folders' => PublicSite::files() ? Folder::where('is_public', true)->whereNull('deleted_at')->count() : null,
            ],
            'latestBooks' => $books ? Book::query()->withAvailability()->latest('id')->limit(12)->get() : [],
            'latestTheses' => $theses ? Folder::whereIn('id', $thesisIds)->latest('id')->limit(6)->get()
                ->map(fn (Folder $f) => [
                    'id' => $f->id, 'title' => $f->thesis_title ?: $f->name, 'researcher' => $f->researcher_name,
                    'degree' => $f->degree, 'year' => $f->publication_year, 'department' => $f->department,
                ]) : [],
            'publicFolders' => PublicSite::files() ? Folder::where('is_public', true)->whereNull('deleted_at')->orderBy('name')->limit(8)->get(['id', 'name']) : [],
            'degreeLabels' => ThesisCatalog::DEGREE_LABELS,
        ]);
    }

    public function index(Request $request): Response
    {
        $this->assertGuestAccessEnabled('view');

        return $this->render(null);
    }

    public function show(Request $request, Folder $folder): Response
    {
        $this->assertGuestCanOpen($folder, 'view');
        $this->assertPublic($folder);

        return $this->render($folder);
    }

    public function download(Request $request, File $file): BinaryFileResponse
    {
        if (! $file->folder) {
            throw new NotFoundHttpException;
        }
        $this->assertGuestCanOpen($file->folder, 'download');

        if (! $file->folder || ! $file->folder->isPubliclyReachable()) {
            throw new NotFoundHttpException;
        }

        if (Setting::get('show_captcha_download', false)) {
            app(\App\Services\CaptchaValidator::class)->assertValid($request);
        }

        \App\Models\ActivityLog::record('file.downloaded', "زائر غير مسجّل حمّل الملف \"{$file->name}\"", $file);

        return response()->download($file->searchablePdf() ?? $file->disk()->path($file->path), $file->name);
    }

    public function thumbnail(Request $request, File $file): BinaryFileResponse
    {
        if (! $file->folder) {
            throw new NotFoundHttpException;
        }
        $this->assertGuestCanOpen($file->folder, 'view');

        if (! $file->folder->isPubliclyReachable() || ! $file->thumbnail_path || ! $file->disk()->exists($file->thumbnail_path)) {
            throw new NotFoundHttpException;
        }

        return response()->file($file->disk()->path($file->thumbnail_path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    private function render(?Folder $folder): Response
    {
        $folders = Folder::where('parent_id', $folder?->id)
            ->whereNull('deleted_at')
            ->when($folder === null, fn ($q) => $q->where('is_public', true))
            ->orderBy('name')
            ->get();

        $files = $folder
            ? File::where('folder_id', $folder->id)->whereNull('deleted_at')->orderBy('name')->get()
            : collect();

        return Inertia::render('Guest/Browse', [
            'currentFolder' => $folder,
            'breadcrumbs' => $folder?->breadcrumbs() ?? [],
            'folders' => $folders,
            'files' => $files,
            'canDownload' => RolePermission::allows('guest', 'download'),
            'filesHome' => PublicSite::files(),
        ]);
    }

    private function assertGuestAccessEnabled(string $action): void
    {
        if (! PublicSite::files() || ! RolePermission::allows('guest', $action)) {
            throw new NotFoundHttpException;
        }
    }

    /** Public files, or a public thesis (and what is inside it) when only theses are switched on. */
    private function assertGuestCanOpen(Folder $folder, string $action): void
    {
        if (PublicSite::files() && RolePermission::allows('guest', $action)) {
            return;
        }
        $inThesis = false;
        for ($node = $folder, $i = 0; $node && $i < 50; $node = $node->parent_id ? Folder::find($node->parent_id) : null, $i++) {
            if ($node->folder_type === 'thesis') {
                $inThesis = true;
                break;
            }
        }
        if (! $inThesis || ! PublicSite::theses() || ($action === 'download' && ! RolePermission::allows('guest', 'download'))) {
            throw new NotFoundHttpException;
        }
    }

    private function assertPublic(Folder $folder): void
    {
        if (! $folder->isPubliclyReachable()) {
            throw new NotFoundHttpException;
        }
    }
}
