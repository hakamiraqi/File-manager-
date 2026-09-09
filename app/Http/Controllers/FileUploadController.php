<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractFileText;
use App\Jobs\GenerateThumbnail;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\FileUploadSession;
use App\Models\Folder;
use App\Services\ExtensionFilter;
use App\Services\NotificationDispatcher;
use App\Services\StorageQuotaService;
use App\Services\UniqueNamer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FileUploadController extends Controller
{
    public function __construct(private readonly StorageQuotaService $quota) {}

    /**
     * Start a new (possibly chunked) upload. Returns an identifier the
     * client will attach every subsequent chunk request to.
     */
    public function init(Request $request, ExtensionFilter $extensionFilter): JsonResponse
    {
        if (! $request->user()->canPerform('upload')) {
            throw new AuthorizationException('لا تملك صلاحية رفع الملفات.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
        ]);

        $user = $request->user();
        $extension = strtolower(pathinfo($data['name'], PATHINFO_EXTENSION));

        if (in_array($extension, config('filenest.forbidden_extensions'), true) || ! $extensionFilter->isAllowed($extension)) {
            throw ValidationException::withMessages(['name' => 'نوع هذا الملف غير مسموح به.']);
        }

        $maxUploadBytes = config('filenest.max_upload_mb') * 1024 * 1024;
        if ($data['size'] > $maxUploadBytes) {
            throw ValidationException::withMessages(['size' => 'حجم الملف يتجاوز الحد الأقصى المسموح به.']);
        }

        if ($data['folder_id'] ?? null) {
            $folder = Folder::where('user_id', $user->id)->whereNull('deleted_at')->findOrFail($data['folder_id']);
            $this->authorize('view', $folder);
        }

        $this->quota->assertHasSpace($user, $data['size']);

        $chunkSize = config('filenest.chunk_size_mb') * 1024 * 1024;
        $totalChunks = (int) ceil($data['size'] / $chunkSize);

        $session = FileUploadSession::create([
            'user_id' => $user->id,
            'folder_id' => $data['folder_id'] ?? null,
            'identifier' => (string) Str::uuid(),
            'original_name' => $data['name'],
            'total_size' => $data['size'],
            'total_chunks' => max(1, $totalChunks),
            'received_chunks' => [],
        ]);

        return response()->json([
            'identifier' => $session->identifier,
            'chunk_size' => $chunkSize,
            'total_chunks' => $session->total_chunks,
        ]);
    }

    public function chunk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'uuid'],
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);

        $session = FileUploadSession::where('user_id', $request->user()->id)
            ->where('identifier', $data['identifier'])
            ->where('status', 'uploading')
            ->firstOrFail();

        $chunkDir = storage_path('app/filemanager/'.$session->chunkDir());
        if (! is_dir($chunkDir)) {
            mkdir($chunkDir, 0755, true);
        }

        $request->file('chunk')->move($chunkDir, (string) $data['index']);

        $received = $session->received_chunks ?? [];
        if (! in_array($data['index'], $received, true)) {
            $received[] = $data['index'];
        }
        $session->update(['received_chunks' => $received]);

        return response()->json([
            'received' => count($received),
            'total_chunks' => $session->total_chunks,
        ]);
    }

    public function finalize(Request $request, NotificationDispatcher $notifier): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'uuid'],
        ]);

        $session = FileUploadSession::where('user_id', $request->user()->id)
            ->where('identifier', $data['identifier'])
            ->where('status', 'uploading')
            ->firstOrFail();

        if (! $session->isComplete()) {
            throw ValidationException::withMessages(['identifier' => 'لم يتم استلام جميع أجزاء الملف بعد.']);
        }

        $user = $request->user();
        $disk = Storage::disk(config('filenest.disk'));
        $chunkDir = storage_path('app/filemanager/'.$session->chunkDir());

        $extension = pathinfo($session->original_name, PATHINFO_EXTENSION);
        $storedName = Str::uuid().($extension ? ".{$extension}" : '');
        $relativePath = "{$user->id}/{$storedName}";
        $disk->makeDirectory((string) $user->id);
        $destination = $disk->path($relativePath);

        $out = fopen($destination, 'wb');
        for ($i = 0; $i < $session->total_chunks; $i++) {
            $chunkPath = "{$chunkDir}/{$i}";
            if (! is_file($chunkPath)) {
                fclose($out);
                @unlink($destination);
                throw ValidationException::withMessages(['identifier' => 'جزء مفقود من الملف، الرجاء إعادة الرفع.']);
            }
            fwrite($out, file_get_contents($chunkPath));
        }
        fclose($out);

        $actualSize = filesize($destination);

        try {
            $this->quota->assertHasSpace($user, $actualSize);
        } catch (ValidationException $e) {
            @unlink($destination);
            $this->cleanupSession($session);
            throw $e;
        }

        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => $session->folder_id,
            'name' => UniqueNamer::forFile($user->id, $session->folder_id, $session->original_name),
            'disk' => config('filenest.disk'),
            'path' => $relativePath,
            'extension' => $extension ?: null,
            'mime_type' => mime_content_type($destination) ?: 'application/octet-stream',
            'size' => $actualSize,
        ]);

        $this->quota->increment($user, $actualSize);

        $this->cleanupSession($session);

        ActivityLog::record('file.uploaded', "رفع الملف \"{$file->name}\"", $file);
        $notifier->uploadOccurred($user, $file->name);
        ExtractFileText::dispatch($file->id);
        GenerateThumbnail::dispatch($file->id);

        return response()->json(['file' => $file]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate(['identifier' => ['required', 'uuid']]);

        $session = FileUploadSession::where('user_id', $request->user()->id)
            ->where('identifier', $data['identifier'])
            ->first();

        if ($session) {
            $this->cleanupSession($session);
        }

        return response()->json(['status' => 'ok']);
    }

    private function cleanupSession(FileUploadSession $session): void
    {
        $dir = storage_path('app/filemanager/'.$session->chunkDir());
        if (is_dir($dir)) {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
        $session->delete();
    }
}
