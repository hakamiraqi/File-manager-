<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Folder
 */
class FolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => 'folder',
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'color' => $this->color,
            'is_public' => (bool) $this->is_public,
            'folder_type' => $this->folder_type,
            'researcher_name' => $this->researcher_name,
            'supervisor_name' => $this->supervisor_name,
            'thesis_title' => $this->thesis_title,
            'publisher' => $this->publisher,
            'digital_library' => $this->digital_library,
            'publication_year' => $this->publication_year,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
