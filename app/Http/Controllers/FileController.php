<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Services\UniqueNamer;
use App\Services\ZipDownloadBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $isMove = array_key_exists('folder_id', $data) && $data['folder_id'] !== $file->folder_id;
        $isRename = isset($data['name']) && $data['name'] !== $file->name;

        if ($isMove && ! $request->user()->canPerform('move')) {
            throw new AuthorizationException('لا تملك صلاحية نقل الملفات.');
        }

        if ($isRename && ! $request->user()->canPerform('rename')) {
            throw new AuthorizationException('لا تملك صلاحية إعادة تسمية الملفات.');
        }

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

    public function copy(Request $request, File $file): RedirectResponse
    {
        $this->authorize('view', $file);

        if (! $request->user()->canPerform('copy')) {
            throw new AuthorizationException('لا تملك صلاحية نسخ الملفات.');
        }

        $data = $request->validate([
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
        ]);

        $user = $request->user();
        $targetFolderId = $data['folder_id'] ?? $file->folder_id;

        if ($targetFolderId) {
            $folder = Folder::where('user_id', $user->id)->whereNull('deleted_at')->findOrFail($targetFolderId);
            $this->authorize('view', $folder);
        }

        app(\App\Services\StorageQuotaService::class)->assertHasSpace($user, $file->size);

        $extension = $file->extension ? ".{$file->extension}" : '';
        $storedName = \Illuminate\Support\Str::uuid().$extension;
        $relativePath = "{$user->id}/{$storedName}";

        $disk = $file->disk();
        $disk->makeDirectory((string) $user->id);
        $disk->copy($file->path, $relativePath);

        $copy = File::create([
            'user_id' => $user->id,
            'folder_id' => $targetFolderId,
            'name' => UniqueNamer::forFile($user->id, $targetFolderId, $file->name),
            'disk' => $file->disk,
            'path' => $relativePath,
            'extension' => $file->extension,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
        ]);

        // The bytes are identical to the source file, so the already
        // extracted text and thumbnail (if any) are copied over instead of
        // re-parsing/re-rendering them.
        $copy->forceFill([
            'content_text' => $file->content_text,
            'content_indexed_at' => $file->content_indexed_at,
        ])->save();

        if ($file->thumbnail_path) {
            $thumbCopy = app(\App\Services\ThumbnailGenerator::class)->randomThumbnailPath($user->id);
            $disk->copy($file->thumbnail_path, $thumbCopy);
            $copy->forceFill(['thumbnail_path' => $thumbCopy])->save();
        }

        app(\App\Services\StorageQuotaService::class)->increment($user, $file->size);

        ActivityLog::record('file.copied', "نسخ الملف \"{$file->name}\"", $copy);

        return back()->with('success', 'تم نسخ الملف.');
    }

    public function destroy(Request $request, File $file): RedirectResponse
    {
        $this->authorize('delete', $file);

        if (! $request->user()->canPerform('delete')) {
            throw new AuthorizationException('لا تملك صلاحية حذف الملفات.');
        }

        $file->delete();

        ActivityLog::record('file.trashed', "نقل الملف \"{$file->name}\" إلى سلة المحذوفات", $file);

        return back()->with('success', 'تم نقل الملف إلى سلة المحذوفات.');
    }

    public function download(Request $request, File $file): BinaryFileResponse
    {
        $this->authorize('view', $file);

        if (! $request->user()->canPerform('download')) {
            throw new AuthorizationException('لا تملك صلاحية تحميل الملفات.');
        }

        ActivityLog::record('file.downloaded', "حمّل الملف \"{$file->name}\"", $file);
        app(\App\Services\NotificationDispatcher::class)->downloadOccurred($file->user, $file->name);

        return response()->download($file->disk()->path($file->path), $file->name);
    }

    /**
     * Inline preview. For local-disk media this returns a BinaryFileResponse
     * so Symfony negotiates HTTP Range requests automatically, which is what
     * lets a <video>/<audio> element seek instead of re-downloading the file
     * from the start on every scrub.
     */
    public function preview(Request $request, File $file): StreamedResponse|BinaryFileResponse
    {
        $this->authorize('view', $file);

        if (! $request->user()->canPerform('view')) {
            throw new AuthorizationException('لا تملك صلاحية عرض الملفات.');
        }

        if ($file->disk === 'filemanager' || $file->disk === 'local') {
            return response()->file($file->disk()->path($file->path), [
                'Content-Type' => $file->mime_type,
            ]);
        }

        return $file->disk()->response($file->path, $file->name, [
            'Content-Type' => $file->mime_type,
        ]);
    }

    /**
     * Word / PowerPoint / Excel shown as PDF (converted once by LibreOffice, then kept next to the
     * other generated files until the document changes).
     */
    public function documentPreview(Request $request, File $file, \App\Services\LibreOfficeConverter $office): BinaryFileResponse
    {
        $this->authorize('view', $file);

        if (! $request->user()->canPerform('view')) {
            throw new AuthorizationException('لا تملك صلاحية عرض الملفات.');
        }

        abort_unless($office->available() && $office->supports((string) $file->extension), 404);

        $cached = storage_path('app/previews/'.$file->id.'-'.$file->size.'-'.optional($file->updated_at)->timestamp.'.pdf');
        if (! is_file($cached)) {
            $source = $file->disk()->path($file->path);
            abort_unless(is_file($source), 404);
            // One conversion at a time per file: a second viewer waits for the first one's result.
            $lock = \Illuminate\Support\Facades\Cache::lock('preview-'.$file->id, 240);
            $lock->block(200, function () use ($office, $source, $cached, $file) {
                if (! is_file($cached)) {
                    foreach (glob(storage_path('app/previews/'.$file->id.'-*.pdf')) ?: [] as $old) {
                        @unlink($old);
                    }
                    abort_unless($office->toPdf($source, $cached, (string) $file->extension), 422, 'تعذّر تحويل المستند للمعاينة.');
                }
            });
        }

        return response()->file($cached, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
        ]);
    }

    /**
     * Serves one alternate-resolution rendition produced by VideoTranscoder
     * (see the file_qualities.quality-selector feature). Same Range-friendly
     * BinaryFileResponse approach as preview() so seeking still works.
     */
    public function previewQuality(Request $request, File $file, int $quality): BinaryFileResponse
    {
        $this->authorize('view', $file);

        if (! $request->user()->canPerform('view')) {
            throw new AuthorizationException('لا تملك صلاحية عرض الملفات.');
        }

        $variant = $file->qualities()->where('height', $quality)->firstOrFail();

        return response()->file($file->disk()->path($variant->path), [
            'Content-Type' => 'video/mp4',
        ]);
    }

    public function thumbnail(Request $request, File $file): BinaryFileResponse
    {
        $this->authorize('view', $file);

        abort_unless($file->thumbnail_path && $file->disk()->exists($file->thumbnail_path), 404);

        return response()->file($file->disk()->path($file->thumbnail_path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * The full text DocumentTextExtractor pulled from this document (PDF,
     * Word, PowerPoint, etc.) — deliberately a separate on-demand endpoint
     * rather than part of the normal file listing, since content_text can
     * be large and most listings never need it.
     */
    public function content(Request $request, File $file): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $file);

        if (! $request->user()->canPerform('view')) {
            throw new AuthorizationException('لا تملك صلاحية عرض الملفات.');
        }

        return response()->json(['content' => $file->content_text]);
    }

    public function downloadZip(Request $request, ZipDownloadBuilder $builder): BinaryFileResponse
    {
        if (! $request->user()->canPerform('download')) {
            throw new AuthorizationException('لا تملك صلاحية تحميل الملفات.');
        }

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

        $maxFiles = (int) \App\Models\Setting::get('max_zip_files', 2000);
        abort_if($files->count() + $folders->sum(fn ($f) => $f->files()->count()) > $maxFiles, 422, 'عدد الملفات يتجاوز الحد الأقصى المسموح به لأرشيف واحد.');

        $zipPath = $builder->build($files, $folders);

        ActivityLog::record('file.bulk_download', 'حمّل عناصر متعددة كملف مضغوط');

        return response()->download($zipPath, 'archive.zip')->deleteFileAfterSend();
    }
}
