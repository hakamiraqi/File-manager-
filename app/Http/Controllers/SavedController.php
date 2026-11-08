<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\SavedItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «المحفوظات» (by request, 2026-11-05): files and folders the user saved
 * with the bookmark button, and the files they downloaded, on one page.
 */
class SavedController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $saved = SavedItem::where('user_id', $user->id)
            ->with('savable')
            ->latest('id')
            ->get()
            // Deleted (or no longer shared) items drop out by themselves.
            ->filter(fn (SavedItem $s) => $s->savable && $user->can('view', $s->savable))
            ->map(fn (SavedItem $s) => [
                'id' => $s->id,
                'type' => $s->savable instanceof Folder ? 'folder' : 'file',
                'saved_at' => $s->created_at?->toIso8601String(),
                'item' => $this->present($s->savable),
            ])
            ->values();

        // Downloads: the last time each file was downloaded, newest first.
        $downloads = ActivityLog::query()
            ->where('user_id', $user->id)
            ->where('action', 'file.downloaded')
            ->where('subject_type', (new File)->getMorphClass())
            ->whereNull('downloads_hidden_at')
            ->selectRaw('subject_id, MAX(created_at) as last_at, COUNT(*) as times')
            ->groupBy('subject_id')
            ->orderByDesc('last_at')
            ->limit(300)
            ->get();
        $files = File::with('qualities')->whereIn('id', $downloads->pluck('subject_id'))->get()->keyBy('id');
        $downloads = $downloads
            ->filter(fn ($d) => ($f = $files->get($d->subject_id)) && $user->can('view', $f))
            ->map(fn ($d) => [
                'id' => (int) $d->subject_id,
                'type' => 'file',
                'downloaded_at' => \Illuminate\Support\Carbon::parse($d->last_at)->toIso8601String(),
                'times' => (int) $d->times,
                'item' => $this->present($files->get($d->subject_id)),
            ])
            ->values();

        return Inertia::render('Saved/Index', [
            'saved' => $saved,
            'downloads' => $downloads,
        ]);
    }

    /** Save or unsave a file or folder. */
    public function toggle(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:file,folder'],
            'id' => ['required', 'integer'],
        ]);
        $model = $data['type'] === 'folder' ? Folder::findOrFail($data['id']) : File::findOrFail($data['id']);
        $this->authorize('view', $model);

        $keys = ['user_id' => $request->user()->id, 'savable_type' => $model->getMorphClass(), 'savable_id' => $model->getKey()];
        $existing = SavedItem::where($keys)->first();
        if ($existing) {
            $existing->delete();
            $saved = false;
        } else {
            SavedItem::create($keys);
            $saved = true;
        }

        if ($request->wantsJson()) {
            return response()->json(['saved' => $saved]);
        }

        return back()->with('success', $saved ? 'تم الحفظ في المحفوظات.' : 'أُزيل من المحفوظات.');
    }

    /** Hide a file (or every file) from the downloads list; the activity log itself stays. */
    public function forgetDownload(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer']]);

        ActivityLog::where('user_id', $request->user()->id)
            ->where('action', 'file.downloaded')
            ->where('subject_type', (new File)->getMorphClass())
            ->when($data['id'] ?? null, fn ($q, $id) => $q->where('subject_id', $id))
            ->whereNull('downloads_hidden_at')
            ->update(['downloads_hidden_at' => now()]);

        return back()->with('success', 'تم التحديث.');
    }

    private function present(File|Folder $model): array
    {
        if ($model instanceof Folder) {
            return [
                'id' => $model->id,
                'name' => $model->name,
                'folder_type' => $model->folder_type,
                'parent_id' => $model->parent_id,
                'created_at' => $model->created_at?->toIso8601String(),
            ];
        }

        return $model->toArray();
    }
}
