<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FileResource;
use App\Http\Resources\FolderResource;
use App\Models\File;
use App\Models\Folder;
use App\Models\Setting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrowseController extends Controller
{
    /**
     * List the folders/files inside a folder (or the root when `folder` is
     * omitted), or run a content+name search across everything when `q` is
     * given. Mirrors the web dashboard's listing so the Flutter client sees
     * the exact same data shape.
     */
    public function index(Request $request): JsonResponse
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

        $currentFolder = $folderId
            ? Folder::where('user_id', $user->id)->whereNull('deleted_at')->findOrFail($folderId)
            : null;

        $foldersQuery = Folder::query()
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->when($search === '', fn ($q) => $q->where('parent_id', $currentFolder?->id))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"));

        $filesQuery = File::query()
            ->with('qualities')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->when($search === '', fn ($q) => $q->where('folder_id', $currentFolder?->id))
            ->when($search !== '', fn ($q) => $this->applySearch($q, $search));

        $sortColumn = in_array($sort, ['name', 'size', 'created_at'], true) ? $sort : 'name';

        $folders = $foldersQuery->orderBy($sortColumn === 'size' ? 'name' : $sortColumn, $direction)->get();
        $files = $filesQuery->orderBy($sortColumn, $direction)->get();

        if ($search !== '') {
            $files->each(function (File $file) use ($search) {
                if (! str_contains(mb_strtolower($file->name), mb_strtolower($search)) && $file->content_text) {
                    $file->content_snippet = $this->buildSnippet($file->content_text, $search);
                }
            });
        }

        return response()->json([
            'current_folder' => $currentFolder ? new FolderResource($currentFolder) : null,
            'breadcrumbs' => FolderResource::collection($currentFolder?->breadcrumbs() ?? []),
            'folders' => FolderResource::collection($folders),
            'files' => FileResource::collection($files),
            'filters' => [
                'q' => $search,
                'sort' => $sortColumn,
                'direction' => $direction,
            ],
        ]);
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereFullText(['name', 'content_text'], $search);
            });
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('content_text', 'like', "%{$search}%");
        });
    }

    private function buildSnippet(string $content, string $search, int $radius = 80): ?string
    {
        $position = mb_stripos($content, $search);

        if ($position === false) {
            return mb_substr($content, 0, $radius * 2).'…';
        }

        $start = max(0, $position - $radius);
        $length = mb_strlen($search) + ($radius * 2);
        $excerpt = mb_substr($content, $start, $length);

        return ($start > 0 ? '…' : '').trim($excerpt).'…';
    }
}
