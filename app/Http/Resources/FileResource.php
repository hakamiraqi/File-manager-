<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\File
 */
class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'file',
            'name' => $this->name,
            'folder_id' => $this->folder_id,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'human_size' => $this->human_size,
            'is_indexed' => $this->is_indexed,
            'is_previewable' => $this->isPreviewable(),
            'has_thumbnail' => (bool) $this->thumbnail_path,
            'content_snippet' => $this->content_snippet ?? null,
            'download_url' => route('api.v1.files.download', $this->id),
            'preview_url' => route('api.v1.files.preview', $this->id),
            // ?v= changes with the picture (a new book cover), so the app does not keep the old one.
            'thumbnail_url' => $this->thumbnail_path ? route('api.v1.files.thumbnail', ['file' => $this->id, 'v' => substr(md5((string) $this->thumbnail_path), 0, 8)]) : null,
            // Alternate-resolution renditions from VideoTranscoder, sorted
            // highest-first — [] for non-video files or when the `qualities`
            // relation wasn't eager-loaded by the caller. The File model's
            // own accessor points `src` at the web route (session auth), so
            // it's rebuilt here against the Bearer-token API route instead.
            'video_qualities' => collect($this->video_qualities)->map(fn (array $q) => [
                'height' => $q['height'],
                'src' => route('api.v1.files.preview-quality', [$this->id, $q['height']]),
            ])->values(),
            'subtitles_url' => $this->isVideo() ? route('api.v1.subtitles.index', $this->id) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
