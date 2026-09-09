<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Folder;
use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FileManagerController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        if (! $user->canPerform('view')) {
            throw new AuthorizationException('لا تملك صلاحية عرض الملفات.');
        }

        $folderId = $request->integer('folder') ?: null;
        $globalSearchEnabled = (bool) Setting::get('global_search', true);
        $search = $globalSearchEnabled ? trim((string) $request->string('q')) : '';
        $sort = $request->string('sort', 'name')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';

        $currentFolder = $folderId ? Folder::where('user_id', $user->id)->whereNull('deleted_at')->findOrFail($folderId) : null;

        $foldersQuery = Folder::query()
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->when($search === '', fn ($q) => $q->where('parent_id', $currentFolder?->id))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"));

        $filesQuery = File::query()
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->when($search === '', fn ($q) => $q->where('folder_id', $currentFolder?->id))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"));

        $sortColumn = in_array($sort, ['name', 'size', 'created_at'], true) ? $sort : 'name';

        $folders = $foldersQuery->orderBy($sortColumn === 'size' ? 'name' : $sortColumn, $direction)->get();
        $files = $filesQuery->orderBy($sortColumn, $direction)->get();

        return Inertia::render('FileManager/Index', [
            'currentFolder' => $currentFolder,
            'breadcrumbs' => $currentFolder?->breadcrumbs() ?? [],
            'folders' => $folders,
            'files' => $files,
            'filters' => [
                'q' => $search,
                'sort' => $sortColumn,
                'direction' => $direction,
            ],
        ]);
    }
}
