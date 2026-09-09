<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Services\StorageQuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrashController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('FileManager/Trash', [
            'folders' => Folder::onlyTrashed()->where('user_id', $user->id)->orderByDesc('deleted_at')->get(),
            'files' => File::onlyTrashed()->where('user_id', $user->id)->orderByDesc('deleted_at')->get(),
            'retentionDays' => config('filenest.trash_retention_days'),
        ]);
    }

    public function restore(Request $request): RedirectResponse
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

        return back()->with('success', 'تمت الاستعادة بنجاح.');
    }

    public function forceDelete(Request $request, string $type, int $id, StorageQuotaService $quota): RedirectResponse
    {
        abort_unless(in_array($type, ['file', 'folder'], true), 404);

        $user = $request->user();

        if ($type === 'file') {
            $file = File::onlyTrashed()->where('user_id', $user->id)->findOrFail($id);
            $file->disk()->delete($file->path);
            $file->disk()->delete((string) $file->thumbnail_path);
            $quota->decrement($user, $file->size);
            $file->forceDelete();
        } else {
            $folder = Folder::onlyTrashed()->where('user_id', $user->id)->findOrFail($id);
            $this->purgeFolder($folder, $quota, $user);
        }

        ActivityLog::record("{$type}.deleted_permanently", 'حذف عنصر نهائياً من سلة المحذوفات');

        return back()->with('success', 'تم الحذف النهائي.');
    }

    public function empty(Request $request, StorageQuotaService $quota): RedirectResponse
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

        return back()->with('success', 'تم إفراغ سلة المحذوفات.');
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
