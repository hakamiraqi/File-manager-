<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Folder;
use App\Services\UniqueNamer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function tree(Request $request): JsonResponse
    {
        $folders = Folder::where('user_id', $request->user()->id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name']);

        return response()->json($folders);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();

        if ($data['parent_id'] ?? null) {
            $this->authorizeParent($request, $data['parent_id']);
        }

        $folder = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $data['parent_id'] ?? null,
            'name' => UniqueNamer::forFolder($user->id, $data['parent_id'] ?? null, $data['name']),
        ]);

        ActivityLog::record('folder.created', "أنشأ المجلد \"{$folder->name}\"", $folder);

        return back()->with('success', 'تم إنشاء المجلد بنجاح.');
    }

    public function update(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorize('update', $folder);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:folders,id'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        if (array_key_exists('parent_id', $data)) {
            if ($data['parent_id'] === $folder->id || in_array($data['parent_id'], $folder->descendantIds(), true)) {
                return back()->withErrors(['parent_id' => 'لا يمكن نقل المجلد إلى داخل نفسه.']);
            }

            if ($data['parent_id']) {
                $this->authorizeParent($request, $data['parent_id']);
            }
        }

        if (isset($data['name'])) {
            $data['name'] = UniqueNamer::forFolder(
                $folder->user_id,
                $data['parent_id'] ?? $folder->parent_id,
                $data['name'],
                $folder->id,
            );
        }

        $folder->update($data);

        ActivityLog::record('folder.updated', "حدّث المجلد \"{$folder->name}\"", $folder);

        return back()->with('success', 'تم تحديث المجلد.');
    }

    public function destroy(Request $request, Folder $folder): RedirectResponse
    {
        $this->authorize('delete', $folder);

        $folder->delete();

        ActivityLog::record('folder.trashed', "نقل المجلد \"{$folder->name}\" إلى سلة المحذوفات", $folder);

        return back()->with('success', 'تم نقل المجلد إلى سلة المحذوفات.');
    }

    private function authorizeParent(Request $request, int $parentId): void
    {
        $parent = Folder::where('user_id', $request->user()->id)->whereNull('deleted_at')->findOrFail($parentId);
        $this->authorize('view', $parent);
    }
}
