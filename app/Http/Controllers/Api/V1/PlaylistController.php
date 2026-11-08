<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlaylistResource;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlaylistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $playlists = Playlist::where('user_id', $request->user()->id)
            ->withCount('items')
            ->with(['items' => fn ($q) => $q->orderBy('order')->limit(1), 'items.file'])
            ->latest()
            ->get();

        return response()->json(['playlists' => PlaylistResource::collection($playlists)]);
    }

    public function picker(Request $request): JsonResponse
    {
        $fileId = $request->integer('file_id') ?: null;

        $playlists = Playlist::where('user_id', $request->user()->id)
            ->withCount('items')
            ->latest()
            ->get()
            ->map(fn (Playlist $playlist) => [
                'id' => $playlist->id,
                'name' => $playlist->name,
                'items_count' => $playlist->items_count,
                'contains_file' => $fileId ? $playlist->items()->where('file_id', $fileId)->exists() : false,
            ]);

        return response()->json(['playlists' => $playlists]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'file_id' => ['nullable', 'integer'],
        ]);

        $playlist = Playlist::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
        ]);

        ActivityLog::record('playlist.created', "أنشأ قائمة تشغيل \"{$playlist->name}\"", $playlist);

        if (! empty($data['file_id'])) {
            $file = File::where('user_id', $request->user()->id)->whereNull('deleted_at')->findOrFail($data['file_id']);
            $this->authorize('view', $file);
            $this->addFileToPlaylist($playlist, $file);
        }

        return response()->json(['playlist' => new PlaylistResource($playlist->load('items.file'))], 201);
    }

    public function show(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorize('view', $playlist);

        $playlist->load(['items' => fn ($q) => $q->orderBy('order'), 'items.file.qualities']);

        return response()->json(['playlist' => new PlaylistResource($playlist)]);
    }

    public function update(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorize('update', $playlist);

        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $playlist->update($data);

        return response()->json(['playlist' => new PlaylistResource($playlist)]);
    }

    public function destroy(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorize('delete', $playlist);

        $playlist->delete();

        ActivityLog::record('playlist.deleted', "حذف قائمة التشغيل \"{$playlist->name}\"");

        return response()->json(['message' => 'تم حذف قائمة التشغيل.']);
    }

    public function addItem(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorize('update', $playlist);

        $data = $request->validate(['file_id' => ['required', 'integer']]);

        $file = File::where('user_id', $request->user()->id)->whereNull('deleted_at')->findOrFail($data['file_id']);
        $this->authorize('view', $file);

        $this->addFileToPlaylist($playlist, $file);

        return response()->json(['playlist' => new PlaylistResource($playlist->load('items.file'))]);
    }

    public function removeItem(Request $request, Playlist $playlist, PlaylistItem $item): JsonResponse
    {
        $this->authorize('update', $playlist);
        abort_unless($item->playlist_id === $playlist->id, 404);

        $item->delete();

        return response()->json(['message' => 'تمت إزالة الملف من قائمة التشغيل.']);
    }

    public function reorder(Request $request, Playlist $playlist): JsonResponse
    {
        $this->authorize('update', $playlist);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $itemIds = PlaylistItem::where('playlist_id', $playlist->id)->pluck('id');

        foreach ($data['order'] as $index => $itemId) {
            if (! $itemIds->contains($itemId)) {
                continue;
            }
            PlaylistItem::where('id', $itemId)->update(['order' => $index]);
        }

        return response()->json(['playlist' => new PlaylistResource($playlist->load(['items' => fn ($q) => $q->orderBy('order'), 'items.file']))]);
    }

    private function addFileToPlaylist(Playlist $playlist, File $file): void
    {
        if (! $file->isVideo() && ! $file->isAudio()) {
            throw ValidationException::withMessages(['file_id' => 'يمكن إضافة ملفات الفيديو والصوت فقط إلى قوائم التشغيل.']);
        }

        if ($playlist->items()->where('file_id', $file->id)->exists()) {
            throw ValidationException::withMessages(['file_id' => 'هذا الملف موجود بالفعل في قائمة التشغيل.']);
        }

        $nextOrder = ((int) $playlist->items()->max('order')) + 1;

        PlaylistItem::create([
            'playlist_id' => $playlist->id,
            'file_id' => $file->id,
            'order' => $nextOrder,
        ]);
    }
}
