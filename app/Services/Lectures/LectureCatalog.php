<?php

namespace App\Services\Lectures;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * «المحاضرات المرئية»: every video the user may watch, wherever it sits in the folders, and the
 * folders that hold them, so recorded lectures can be found and played without browsing the tree.
 * Who may see what follows the files' own rule (FilePolicy::view): admins everything, others
 * their own uploads and the folders assigned to them, with everything below those folders.
 */
class LectureCatalog
{
    public const LIMIT = 3000;

    /** @return Collection<int, File> the visible videos, natural name order, with their folder */
    public function videos(User $user): Collection
    {
        $visible = $this->visibleFolderIds($user);

        return File::query()
            ->where(fn (Builder $q) => $q->where('mime_type', 'like', 'video/%')
                ->orWhere(fn (Builder $o) => $o->whereIn('extension', File::VIDEO_EXTENSIONS)->where('mime_type', 'not like', 'audio/%')))
            ->when($visible !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('user_id', $user->id)->orWhereIn('folder_id', $visible)))
            ->with(['qualities', 'folder:id,name,parent_id'])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * The folders that hold the videos, newest activity first, each with where it sits
     * («المرحلة الثالثة › التشريح»), how many videos, their size and a few thumbnails.
     *
     * @param  Collection<int, File>  $videos
     */
    public function folders(User $user, Collection $videos): array
    {
        $tree = Folder::get(['id', 'name', 'parent_id'])->keyBy('id');
        $visible = $this->visibleFolderIds($user);
        $visibleSet = $visible === null ? null : array_flip($visible);

        return $videos->groupBy(fn (File $f) => (int) $f->folder_id)
            ->map(function (Collection $files, int $folderId) use ($tree, $visibleSet) {
                $trail = [];
                for ($node = $tree[$tree[$folderId]->parent_id ?? 0] ?? null, $i = 0; $node && $i < 50; $node = $tree[$node->parent_id] ?? null, $i++) {
                    if ($visibleSet !== null && ! isset($visibleSet[$node->id])) {
                        break; // don't reveal folders above what the user was given
                    }
                    array_unshift($trail, $node->name);
                }

                return [
                    'id' => $folderId ?: null,
                    'name' => $folderId ? ($tree[$folderId]->name ?? '—') : null,
                    'path' => array_slice($trail, -3),
                    'count' => $files->count(),
                    'size' => (int) $files->sum('size'),
                    'latest' => $files->max('created_at')?->toIso8601String(),
                    'thumbs' => $files->filter(fn (File $f) => $f->thumbnail_path)->take(4)->pluck('id')->values()->all(),
                    'video_ids' => $files->pluck('id')->values()->all(),
                ];
            })
            ->sortByDesc('latest')->values()->all();
    }

    /** Folder ids the user may open, or null for «all» (admins). @return list<int>|null */
    public function visibleFolderIds(User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }
        $all = Folder::get(['id', 'parent_id', 'user_id'])->keyBy('id');
        $assigned = array_flip($user->assignedFolderIds());
        $memo = [];
        $ok = function (int $id) use (&$ok, &$memo, $all, $assigned, $user): bool {
            if (isset($memo[$id])) {
                return $memo[$id];
            }
            $memo[$id] = false; // guards against a loop in the tree
            $node = $all[$id] ?? null;

            return $memo[$id] = $node !== null && ($node->user_id === $user->id || isset($assigned[$id])
                || ($node->parent_id !== null && $ok((int) $node->parent_id)));
        };

        return $all->keys()->filter(fn ($id) => $ok((int) $id))->map(fn ($id) => (int) $id)->values()->all();
    }
}
