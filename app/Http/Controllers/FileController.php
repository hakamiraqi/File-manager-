<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Services\UniqueNamer;
use App\Services\ZipDownloadBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function update(Request $request, File $file): RedirectResponse
    {
        $this->authorize('update', $file);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'folder_id' => ['sometimes', 'nullable', 'integer', 'exists:folders,id'],
        ]);

        if (array_key_exists('folder_id', $data) && $data['folder_id']) {
            $folder = Folder::where('user_id', $file->user_id)->whereNull('deleted_at')->findOrFail($data['folder_id']);
            $this->authorize('view', $folder);
        }

        if (isset($data['name'])) {
            $data['name'] = UniqueNamer::forFile(
                $file->user_id,
                $data['folder_id'] ?? $file->folder_id,
                $data['name'],
                $file->id,
            );
        }

        $file->update($data);

        ActivityLog::record('file.updated', "حدّث الملف \"{$file->name}\"", $file);

        return back()->with('success', 'تم تحديث الملف.');
    }

    public function destroy(Request $request, File $file): RedirectResponse
    {
        $this->authorize('delete', $file);

        $file->delete();

        ActivityLog::record('file.trashed', "نقل الملف \"{$file->name}\" إلى سلة المحذوفات", $file);

        return back()->with('success', 'تم نقل الملف إلى سلة المحذوفات.');
    }

    public function download(Request $request, File $file): BinaryFileResponse
    {
        $this->authorize('view', $file);

        return response()->download($file->disk()->path($file->path), $file->name);
    }

    public function preview(Request $request, File $file): StreamedResponse
    {
        $this->authorize('view', $file);

        return $file->disk()->response($file->path, $file->name, [
            'Content-Type' => $file->mime_type,
        ]);
    }

    public function downloadZip(Request $request, ZipDownloadBuilder $builder): Response
    {
        $data = $request->validate([
            'file_ids' => ['array'],
            'file_ids.*' => ['integer'],
            'folder_ids' => ['array'],
            'folder_ids.*' => ['integer'],
        ]);

        $user = $request->user();

        $files = File::whereIn('id', $data['file_ids'] ?? [])
            ->where('user_id', $user->id)->whereNull('deleted_at')->get();

        $folders = Folder::whereIn('id', $data['folder_ids'] ?? [])
            ->where('user_id', $user->id)->whereNull('deleted_at')->get();

        abort_if($files->isEmpty() && $folders->isEmpty(), 404);

        $zipPath = $builder->build($files, $folders);

        ActivityLog::record('file.bulk_download', 'حمّل عناصر متعددة كملف مضغوط');

        return response()->download($zipPath, 'archive.zip')->deleteFileAfterSend();
    }
}
