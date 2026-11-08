<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FileResource;
use App\Http\Resources\FolderResource;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Services\StorageQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrashController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'folders' => FolderResource::collection(
                Folder::onlyTrashed()->where('user_id', $user->id)->orderByDesc('deleted_at')->get()
            ),
            'files' => FileResource::collection(
                File::onlyTrashed()->where('user_id', $user->id)->orderByDesc('deleted_at')->get()
            ),
            'retention_days' => config('filenest.trash_retention_days'),
        ]);
    }

    public function restore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:file,folder'],
            'id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $model = $data['type'] === 'file'
            ? File::onlyTrashed()->where('user_id', $user->id)->findOrFail($data['id'])
            : Folder::onlyTrashed()->where('user_id', $user->id)->findOrFail($data['id']);

        $model->restore();

        ActivityLog::record("{$data['type']}.restored", "استعاد \"{$model->name}\" من سلة المحذوفات", $model);

        return response()->json(['message' => 'تمت الاستعادة بنجاح.']);
    }

    public function forceDelete(Request $request, string $type, int $id, StorageQuotaService $quota): JsonResponse
    {
        abort_unless(in_array($type, ['file', 'folder'], true), 404);

        $user = $request->user();

        if ($type === 'file') {
            $file = File::onlyTrashed()->where('user_id', $user->id)->findOrFail($id);
            $file->disk()->delete($file->path);
            if ($file->thumbnail_path) {
                $file->disk()->delete($file->thumbnail_path);
            }
            $quota->decrement($user, $file->size);
            $file->forceDelete();
        } else {
            $folder = Folder::onlyTrashed()->where('user_id', $user->id)->findOrFail($id);
            $this->purgeFolder($folder, $quota, $user);
        }

        ActivityLog::record("{$type}.deleted_permanently", 'حذف عنصر نهائياً من سلة المحذوفات');

        return response()->json(['message' => 'تم الحذف النهائي.']);
    }

    public function empty(Request $request, StorageQuotaService $quota): JsonResponse
    {
        $user = $request->user();

        foreach (File::onlyTrashed()->where('user_id', $user->id)->get() as $file) {
            $file->disk()->delete($file->path);
            $quota->decrement($user, $file->size);
            $file->forceDelete();
        }

        foreach (Folder::onlyTrashed()->where('user_id', $user->id)->whereNull('parent_id')->get() as $folder) {
            $this->purgeFolder($folder, $quota, $user);
        }

        ActivityLog::record('trash.emptied', 'أفرغ سلة المحذوفات بالكامل');

        return response()->json(['message' => 'تم إفراغ سلة المحذوفات.']);
    }

    private function purgeFolder(Folder $folder, StorageQuotaService $quota, $user): void
    {
        foreach (File::withTrashed()->where('folder_id', $folder->id)->get() as $file) {
            $file->disk()->delete($file->path);
            $quota->decrement($user, $file->size);
            $file->forceDelete();
        }

        foreach (Folder::withTrashed()->where('parent_id', $folder->id)->get() as $child) {
            $this->purgeFolder($child, $quota, $user);
        }

        $folder->forceDelete();
    }
}
