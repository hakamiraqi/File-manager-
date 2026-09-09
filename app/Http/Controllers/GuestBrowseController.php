<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use App\Models\RolePermission;
use App\Models\Setting;
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
    public function index(Request $request): Response
    {
        $this->assertGuestAccessEnabled('view');

        return $this->render(null);
    }

    public function show(Request $request, Folder $folder): Response
    {
        $this->assertGuestAccessEnabled('view');
        $this->assertPublic($folder);

        return $this->render($folder);
    }

    public function download(Request $request, File $file): BinaryFileResponse
    {
        $this->assertGuestAccessEnabled('download');

        if (! $file->folder || ! $file->folder->isPubliclyReachable()) {
            throw new NotFoundHttpException;
        }

        if (Setting::get('show_captcha_download', false)) {
            app(\App\Services\CaptchaValidator::class)->assertValid($request);
        }

        \App\Models\ActivityLog::record('file.downloaded', "زائر غير مسجّل حمّل الملف \"{$file->name}\"", $file);

        return response()->download($file->disk()->path($file->path), $file->name);
    }

    public function thumbnail(Request $request, File $file): BinaryFileResponse
    {
        $this->assertGuestAccessEnabled('view');

        if (! $file->folder || ! $file->folder->isPubliclyReachable() || ! $file->thumbnail_path || ! $file->disk()->exists($file->thumbnail_path)) {
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
        ]);
    }

    private function assertGuestAccessEnabled(string $action): void
    {
        if (! Setting::get('guest_access_enabled', false) || ! RolePermission::allows('guest', $action)) {
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
