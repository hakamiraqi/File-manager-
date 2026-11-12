<?php

namespace App\Http\Controllers;

use App\Http\Resources\FileResource;
use App\Models\File;
use App\Services\Lectures\LectureCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «المحاضرات المرئية»: all the videos the user may watch, by folder or as one list. */
class LectureController extends Controller
{
    public function __construct(private readonly LectureCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $videos = $this->catalog->videos($user);

        return Inertia::render('Lectures/Index', [
            'folders' => $this->catalog->folders($user, $videos),
            'videos' => $videos->map(fn (File $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'folder_id' => $f->folder_id,
                'folder_name' => $f->folder?->name,
                'extension' => $f->extension,
                'mime_type' => str_starts_with((string) $f->mime_type, 'video/') ? $f->mime_type : 'video/mp4',
                'size' => $f->size,
                'human_size' => $f->human_size,
                'thumbnail_path' => $f->thumbnail_path,
                'created_at' => $f->created_at?->toIso8601String(),
                'video_qualities' => $f->video_qualities,
            ])->values(),
            'limited' => $videos->count() >= LectureCatalog::LIMIT,
        ]);
    }

    /** The same for the apps (files in the /browse shape). */
    public function api(Request $request): JsonResponse
    {
        $user = $request->user();
        $videos = $this->catalog->videos($user);

        return response()->json([
            'folders' => $this->catalog->folders($user, $videos),
            'videos' => $videos->map(fn (File $f) => (new FileResource($f))->resolve($request) + ['folder_name' => $f->folder?->name])->values(),
        ]);
    }
}
