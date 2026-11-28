<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Folder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A folder's look: its colour, a theme (e.g. a college's colours and cover), a logo and a cover
 * picture. Sub-folders take the look of the nearest folder above them that has one.
 */
class FolderAppearanceController extends Controller
{
    public function update(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorize('update', $folder);
        if (! $request->user()->canPerform('rename_folder')) {
            throw new AuthorizationException('لا تملك صلاحية تعديل مظهر المجلد.');
        }

        $data = $request->validate([
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z0-9\-]+$/'],
            'logo' => ['sometimes', 'nullable', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:3072'],
            'cover' => ['sometimes', 'nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_cover' => ['sometimes', 'boolean'],
        ], [
            'logo.max' => 'حجم الشعار يجب ألا يتجاوز 3 ميغابايت.',
            'cover.max' => 'حجم صورة الغلاف يجب ألا يتجاوز 8 ميغابايت.',
            'logo.mimes' => 'الشعار يجب أن يكون صورة PNG أو JPG أو WEBP أو SVG.',
            'cover.mimes' => 'صورة الغلاف يجب أن تكون PNG أو JPG أو WEBP.',
        ]);

        $changes = [];
        foreach (['color', 'theme'] as $key) {
            if (array_key_exists($key, $data)) {
                $changes[$key] = $data[$key] ?: null;
            }
        }
        foreach (['logo', 'cover'] as $kind) {
            $column = "{$kind}_path";
            if ($request->boolean("remove_{$kind}") || $request->hasFile($kind)) {
                $this->forget($folder->{$column});
                $changes[$column] = null;
            }
            if ($request->hasFile($kind)) {
                $changes[$column] = $this->store($folder, $kind, $request->file($kind));
            }
        }

        $folder->forceFill($changes)->save();
        ActivityLog::record('folder.appearance', "غيّر مظهر المجلد \"{$folder->name}\"", $folder);

        return back()->with('success', 'تم حفظ مظهر المجلد.');
    }

    public function logo(Request $request, Folder $folder): BinaryFileResponse
    {
        return $this->serve($request, $folder, $folder->logo_path);
    }

    public function cover(Request $request, Folder $folder): BinaryFileResponse
    {
        return $this->serve($request, $folder, $folder->cover_path);
    }

    private function serve(Request $request, Folder $folder, ?string $path): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user ? $folder->isAccessibleBy($user)
            : (\App\Support\PublicSite::files() && $folder->isPubliclyReachable()), 404);
        $disk = Storage::disk('local');
        abort_unless($path && $disk->exists($path), 404);

        $response = response()->file($disk->path($path), [
            'Content-Type' => str_ends_with($path, '.svg') ? 'image/svg+xml' : ($disk->mimeType($path) ?: 'image/jpeg'),
            // An uploaded SVG never runs scripts on our pages.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag(md5($path));
        $response->headers->set('Cache-Control', 'private, max-age=86400');
        $response->isNotModified($request);

        return $response;
    }

    private function store(Folder $folder, string $kind, UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;

        return $file->storeAs("folder-looks/{$folder->id}", $kind.'-'.Str::random(10).'.'.$ext, 'local');
    }

    private function forget(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
