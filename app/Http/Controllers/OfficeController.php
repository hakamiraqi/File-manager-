<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractFileText;
use App\Jobs\GenerateThumbnail;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Office\OnlyOffice;
use App\Services\StorageQuotaService;
use App\Services\UniqueNamer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** The office editor: open a file in it, give it the file, take the saved file back, new documents. */
class OfficeController extends Controller
{
    /** Earlier copies kept for each edited file. */
    public const VERSIONS_KEPT = 10;

    public function __construct(private readonly OnlyOffice $office) {}

    public function edit(Request $request, File $file): Response
    {
        $this->authorize('view', $file);
        abort_unless($request->user()->canPerform('view'), 403);
        abort_unless($this->office->enabled(), 404, 'المحرر غير مفعّل على هذا الخادم.');
        abort_unless($this->office->supports($file->extension), 422, 'هذا النوع من الملفات لا يُفتح في المحرر.');

        return Inertia::render('Office/Editor', [
            'file' => ['id' => $file->id, 'name' => $file->name, 'folder_id' => $file->folder_id, 'extension' => $file->extension],
            'config' => $this->office->config($file, $request->user(), app()->getLocale()),
            'script' => $this->office->scriptUrl(),
            'canEdit' => $this->office->canEdit($file, $request->user()),
            'inApp' => (bool) $request->session()->get('in_app'),
        ]);
    }

    /** For the editor only (signed link on the private network): the file as it is. */
    public function download(File $file): BinaryFileResponse
    {
        $path = $file->disk()->path($file->path);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => $file->mime_type ?: 'application/octet-stream']);
    }

    /** The editor reports on a file; when it is saved (2) or force-saved (6) the new copy replaces it. */
    public function callback(Request $request, File $file): JsonResponse
    {
        try {
            $data = $this->trustedBody($request);
        } catch (Throwable $e) {
            Log::warning("Office callback refused for file {$file->id}: {$e->getMessage()}");

            return response()->json(['error' => 1], 403);
        }

        $status = (int) ($data['status'] ?? 0);
        if (! in_array($status, [2, 6], true) || empty($data['url'])) {
            return response()->json(['error' => 0]);
        }

        $editorId = (int) ($data['users'][0] ?? $data['actions'][0]['userid'] ?? 0);
        if ($editor = User::find($editorId)) {
            Auth::setUser($editor);
        }

        try {
            $body = $this->fetch((string) $data['url']);
            $this->replaceContent($file, $body);
            ActivityLog::record('file.updated', "عدّل الملف \"{$file->name}\" في المحرر", $file);
        } catch (Throwable $e) {
            Log::error("Office save failed for file {$file->id}: {$e->getMessage()}");

            return response()->json(['error' => 1, 'message' => 'save failed'], 500);
        }

        return response()->json(['error' => 0]);
    }

    /** «مستند جديد»: a blank Word, Excel or PowerPoint file, opened in the editor. */
    public function create(Request $request, StorageQuotaService $quota): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canPerform('upload'), 403, 'لا تملك صلاحية إنشاء الملفات.');
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(OnlyOffice::NEW))],
            'name' => ['nullable', 'string', 'max:200'],
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
        ]);
        if ($data['folder_id'] ?? null) {
            Folder::findAccessible($user, $data['folder_id']);
        }

        $template = resource_path('office-templates/'.OnlyOffice::NEW[$data['type']]);
        $size = filesize($template);
        $quota->assertHasSpace($user, $size);

        $disk = Storage::disk(config('filenest.disk'));
        $relative = "{$user->id}/".Str::uuid().'.'.$data['type'];
        $disk->makeDirectory((string) $user->id);
        copy($template, $disk->path($relative));

        $default = ['docx' => 'مستند جديد', 'xlsx' => 'جدول جديد', 'pptx' => 'عرض تقديمي جديد'][$data['type']];
        $name = trim((string) ($data['name'] ?? '')) ?: $default;
        if (! str_ends_with(strtolower($name), '.'.$data['type'])) {
            $name .= '.'.$data['type'];
        }

        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => $data['folder_id'] ?? null,
            'name' => UniqueNamer::forFile($user->id, $data['folder_id'] ?? null, $name),
            'disk' => config('filenest.disk'),
            'path' => $relative,
            'extension' => $data['type'],
            'mime_type' => mime_content_type($disk->path($relative)) ?: 'application/octet-stream',
            'size' => $size,
        ]);
        $quota->increment($user, $size);
        ActivityLog::record('file.uploaded', "أنشأ الملف \"{$file->name}\"", $file);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['file' => (new \App\Http\Resources\FileResource($file))->resolve($request)], 201);
        }

        return redirect()->route('office.edit', $file);
    }

    // ----

    /** The callback's body, only when its token is signed with our secret. */
    private function trustedBody(Request $request): array
    {
        $token = $request->input('token');
        if (! $token && preg_match('/^Bearer\s+(.+)$/i', (string) $request->header('Authorization'), $m)) {
            $token = $m[1];
        }
        abort_unless($token, 403);
        $payload = $this->office->verify((string) $token);

        // A header token wraps the body in «payload»; a body token is the body itself.
        return isset($payload['payload']) && is_array($payload['payload']) ? $payload['payload'] : $payload;
    }

    private function fetch(string $url): string
    {
        foreach (array_unique([$this->office->internalise($url), $url]) as $try) {
            try {
                $r = Http::timeout(180)->get($try);
                if ($r->successful() && $r->body() !== '') {
                    return $r->body();
                }
            } catch (Throwable) {
                // try the next address
            }
        }
        throw new \RuntimeException('The saved file could not be fetched from the editor.');
    }

    /** New bytes in place, keeping the last copies, and everything derived from the file refreshed. */
    private function replaceContent(File $file, string $body): void
    {
        $disk = $file->disk();
        $old = (int) $file->size;

        $keep = 'versions/'.$file->id;
        $local = Storage::disk('local');
        if ($disk->exists($file->path)) {
            $local->put($keep.'/'.now()->format('Ymd_His').'.'.$file->extension, $disk->get($file->path));
            $copies = collect($local->files($keep))->sort()->values();
            foreach ($copies->slice(0, max(0, $copies->count() - self::VERSIONS_KEPT)) as $stale) {
                $local->delete($stale);
            }
        }

        $disk->put($file->path, $body);
        $file->forgetSearchablePdf();
        $file->forceFill([
            'size' => strlen($body),
            'content_indexed_at' => null,
            'updated_at' => now(),
        ])->save();

        if ($owner = $file->user) {
            $quota = app(StorageQuotaService::class);
            $diff = strlen($body) - $old;
            $diff >= 0 ? $quota->increment($owner, $diff) : $quota->decrement($owner, -$diff);
        }

        ExtractFileText::dispatch($file->id);
        GenerateThumbnail::dispatch($file->id);
    }
}
