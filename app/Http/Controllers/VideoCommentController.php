<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\VideoComment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VideoCommentController extends Controller
{
    public function index(Request $request, File $file): JsonResponse
    {
        $this->authorize('view', $file);

        $comments = $file->comments()
            ->with('user:id,name')
            ->whereNull('parent_id')
            ->orderBy('created_at')
            ->get(['id', 'user_id', 'body', 'timestamp_seconds', 'created_at']);

        return response()->json(['comments' => $comments]);
    }

    public function store(Request $request, File $file): RedirectResponse
    {
        $this->authorize('view', $file);

        if (! $request->user()->canPerform('comment')) {
            throw new AuthorizationException('لا تملك صلاحية التعليق على الفيديوهات.');
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'timestamp_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $comment = VideoComment::create([
            'file_id' => $file->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
            'timestamp_seconds' => $data['timestamp_seconds'] ?? null,
        ]);

        ActivityLog::record('video_comment.created', "علّق على الفيديو \"{$file->name}\"", $file);

        return back()->with('success', 'تمت إضافة التعليق.');
    }

    /**
     * The comment's own author can always delete it; the video's owner
     * (or an admin) can also remove any comment on their own video.
     */
    public function destroy(Request $request, VideoComment $comment): RedirectResponse
    {
        if ($comment->user_id !== $request->user()->id) {
            $this->authorize('update', $comment->file);
        }

        $file = $comment->file;
        $comment->delete();

        ActivityLog::record('video_comment.deleted', "حذف تعليقاً من الفيديو \"{$file->name}\"", $file);

        return back()->with('success', 'تم حذف التعليق.');
    }
}
