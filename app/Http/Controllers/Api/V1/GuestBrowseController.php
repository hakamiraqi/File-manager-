<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FolderResource;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\RolePermission;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Unauthenticated browsing of folders an admin flagged "public" — the API
 * mirror of GuestBrowseController, gated by the same guest_access_enabled
 * toggle and the guest row of the role permission matrix.
 */
class GuestBrowseController extends Controller
{
    public function index(): JsonResponse
    {
        $this->assertGuestAccessEnabled('view');

        return $this->render(null);
    }

    public function show(Folder $folder): JsonResponse
    {
        $this->assertGuestAccessEnabled('view');
        $this->assertPublic($folder);

        return $this->render($folder);
    }

    public function download(File $file): BinaryFileResponse
    {
        $this->assertGuestAccessEnabled('download');

        if (! $file->folder || ! $file->folder->isPubliclyReachable()) {
            throw new NotFoundHttpException;
        }

        ActivityLog::record('file.downloaded', "زائر غير مسجّل حمّل الملف \"{$file->name}\"", $file);

        return response()->download($file->disk()->path($file->path), $file->name);
    }

    public function thumbnail(File $file): BinaryFileResponse
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

    private function render(?Folder $folder): JsonResponse
    {
        $folders = Folder::where('parent_id', $folder?->id)
            ->whereNull('deleted_at')
            ->when($folder === null, fn ($q) => $q->where('is_public', true))
            ->orderBy('name')
            ->get();

        $files = $folder
            ? File::where('folder_id', $folder->id)->whereNull('deleted_at')->orderBy('name')->get()
            : collect();

        return response()->json([
            'current_folder' => $folder ? new FolderResource($folder) : null,
            'breadcrumbs' => FolderResource::collection($folder?->breadcrumbs() ?? []),
            'folders' => FolderResource::collection($folders),
            // Not FileResource: its download/preview/thumbnail URLs point
            // at the Bearer-token-protected /api/v1/files/* routes, which
            // a guest has no token for — these point at the dedicated
            // unauthenticated guest.* routes instead.
            'files' => $files->map(fn (File $file) => [
                'id' => $file->id,
                'name' => $file->name,
                'extension' => $file->extension,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'human_size' => $file->human_size,
                'has_thumbnail' => (bool) $file->thumbnail_path,
                'download_url' => route('api.v1.public.guest.download', $file->id),
                'thumbnail_url' => $file->thumbnail_path ? route('api.v1.public.guest.thumbnail', $file->id) : null,
            ])->values(),
            'can_download' => RolePermission::allows('guest', 'download'),
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
