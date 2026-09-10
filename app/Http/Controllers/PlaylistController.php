<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * YouTube-style named, user-curated video/audio playlists — distinct from
 * the ad-hoc "same folder, same kind" queue PreviewModal.vue already builds
 * on the fly from whatever files prop it's given. Opening a real playlist
 * simply passes its own ordered file list as that same `files` prop, so
 * the player's next/prev/autoplay logic needs no changes at all.
 */
class PlaylistController extends Controller
{
    public function index(Request $request): Response
    {
        $playlists = Playlist::where('user_id', $request->user()->id)
            ->withCount('items')
            ->with(['items' => fn ($q) => $q->orderBy('order')->limit(1), 'items.file'])
            ->latest()
            ->get();

        return Inertia::render('Playlists/Index', ['playlists' => $playlists]);
    }

    /**
     * JSON-only, for the "Add to playlist" modal opened from the file
     * browser — lists this user's playlists plus whether the given file is
     * already in each one, so the modal can render "أضيفت" instead of an
     * add button.
     */
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

    public function store(Request $request): RedirectResponse
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

        return back()->with('success', 'تم إنشاء قائمة التشغيل.')->with('playlistId', $playlist->id);
    }

    public function show(Request $request, Playlist $playlist): Response
    {
        $this->authorize('view', $playlist);

        $playlist->load(['items' => fn ($q) => $q->orderBy('order'), 'items.file.qualities']);

        return Inertia::render('Playlists/Show', ['playlist' => $playlist]);
    }

    public function update(Request $request, Playlist $playlist): RedirectResponse
    {
        $this->authorize('update', $playlist);

        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $playlist->update($data);

        return back()->with('success', 'تم تحديث اسم القائمة.');
    }

    public function destroy(Request $request, Playlist $playlist): RedirectResponse
    {
        $this->authorize('delete', $playlist);

        $playlist->delete();

        ActivityLog::record('playlist.deleted', "حذف قائمة التشغيل \"{$playlist->name}\"");

        return redirect()->route('playlists.index')->with('success', 'تم حذف قائمة التشغيل.');
    }

    public function addItem(Request $request, Playlist $playlist): RedirectResponse
    {
        $this->authorize('update', $playlist);

        $data = $request->validate(['file_id' => ['required', 'integer']]);

        $file = File::where('user_id', $request->user()->id)->whereNull('deleted_at')->findOrFail($data['file_id']);
        $this->authorize('view', $file);

        $this->addFileToPlaylist($playlist, $file);

        return back()->with('success', 'تمت إضافة الملف إلى قائمة التشغيل.');
    }

    public function removeItem(Request $request, Playlist $playlist, PlaylistItem $item): RedirectResponse
    {
        $this->authorize('update', $playlist);
        abort_unless($item->playlist_id === $playlist->id, 404);

        $item->delete();

        return back()->with('success', 'تمت إزالة الملف من قائمة التشغيل.');
    }

    public function reorder(Request $request, Playlist $playlist): RedirectResponse
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

        return back()->with('success', 'تم إعادة ترتيب القائمة.');
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
