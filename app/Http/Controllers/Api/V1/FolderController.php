<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FolderResource;
use App\Models\ActivityLog;
use App\Models\Folder;
use App\Services\NotificationDispatcher;
use App\Services\UniqueNamer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
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

    public function store(Request $request, NotificationDispatcher $notifier): JsonResponse
    {
        if (! $request->user()->canPerform('new_folder')) {
            throw new AuthorizationException('لا تملك صلاحية إنشاء مجلدات جديدة.');
        }

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
        $notifier->newFolderOccurred($user, $folder->name);

        return response()->json(['folder' => new FolderResource($folder)], 201);
    }

    public function update(Request $request, Folder $folder): JsonResponse
    {
        $this->authorize('update', $folder);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:folders,id'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_public' => ['sometimes', 'boolean'],
            'folder_type' => ['sometimes', 'string', 'in:files,thesis'],
            'researcher_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'supervisor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'thesis_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'publisher' => ['sometimes', 'nullable', 'string', 'max:255'],
            'digital_library' => ['sometimes', 'nullable', 'string', 'max:255'],
            'publication_year' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $isMove = array_key_exists('parent_id', $data) && $data['parent_id'] !== $folder->parent_id;
        $isRename = isset($data['name']) && $data['name'] !== $folder->name;
        $isMetadataUpdate = collect(['folder_type', 'researcher_name', 'supervisor_name', 'thesis_title', 'publisher', 'digital_library', 'publication_year'])
            ->contains(fn ($field) => array_key_exists($field, $data));

        if ($isMove && ! $request->user()->canPerform('move')) {
            throw new AuthorizationException('لا تملك صلاحية نقل المجلدات.');
        }

        if ($isRename && ! $request->user()->canPerform('rename_folder')) {
            throw new AuthorizationException('لا تملك صلاحية إعادة تسمية المجلدات.');
        }

        if ($isMetadataUpdate && ! $request->user()->canPerform('rename_folder')) {
            throw new AuthorizationException('لا تملك صلاحية تعديل معلومات المجلد.');
        }

        if (array_key_exists('is_public', $data) && ! $request->user()->isAdmin()) {
            unset($data['is_public']);
        }

        if (array_key_exists('parent_id', $data)) {
            if ($data['parent_id'] === $folder->id || in_array($data['parent_id'], $folder->descendantIds(), true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'parent_id' => 'لا يمكن نقل المجلد إلى داخل نفسه.',
                ]);
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

        return response()->json(['folder' => new FolderResource($folder)]);
    }

    public function copy(Request $request, Folder $folder): JsonResponse
    {
        $this->authorize('view', $folder);

        if (! $request->user()->canPerform('copy')) {
            throw new AuthorizationException('لا تملك صلاحية نسخ المجلدات.');
        }

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
        ]);

        $targetParentId = $data['parent_id'] ?? $folder->parent_id;

        if ($targetParentId === $folder->id || in_array($targetParentId, $folder->descendantIds(), true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'parent_id' => 'لا يمكن نسخ المجلد إلى داخل نفسه.',
            ]);
        }

        if ($targetParentId) {
            $this->authorizeParent($request, $targetParentId);
        }

        $copy = app(\App\Services\FolderDuplicator::class)->duplicate($folder, $request->user(), $targetParentId);

        ActivityLog::record('folder.copied', "نسخ المجلد \"{$folder->name}\"", $copy);

        return response()->json(['folder' => new FolderResource($copy)], 201);
    }

    public function destroy(Request $request, Folder $folder): JsonResponse
    {
        $this->authorize('delete', $folder);

        if (! $request->user()->canPerform('delete_folder')) {
            throw new AuthorizationException('لا تملك صلاحية حذف المجلدات.');
        }

        $folder->delete();

        ActivityLog::record('folder.trashed', "نقل المجلد \"{$folder->name}\" إلى سلة المحذوفات", $folder);

        return response()->json(['message' => 'تم نقل المجلد إلى سلة المحذوفات.']);
    }

    private function authorizeParent(Request $request, int $parentId): void
    {
        $parent = Folder::where('user_id', $request->user()->id)->whereNull('deleted_at')->findOrFail($parentId);
        $this->authorize('view', $parent);
    }
}
