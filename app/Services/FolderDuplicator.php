<?php

namespace App\Services;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Support\Str;

class FolderDuplicator
{
    public function __construct(private readonly StorageQuotaService $quota) {}

    public function duplicate(Folder $source, User $user, ?int $targetParentId): Folder
    {
        $copy = Folder::create([
            'user_id' => $user->id,
            'parent_id' => $targetParentId,
            'name' => UniqueNamer::forFolder($user->id, $targetParentId, $source->name),
            'color' => $source->color,
        ]);

        foreach ($source->files()->whereNull('deleted_at')->get() as $file) {
            $this->quota->assertHasSpace($user, $file->size);

            $extension = $file->extension ? ".{$file->extension}" : '';
            $relativePath = "{$user->id}/".Str::uuid().$extension;

            $disk = $file->disk();
            $disk->makeDirectory((string) $user->id);
            $disk->copy($file->path, $relativePath);

            File::create([
                'user_id' => $user->id,
                'folder_id' => $copy->id,
                'name' => UniqueNamer::forFile($user->id, $copy->id, $file->name),
                'disk' => $file->disk,
                'path' => $relativePath,
                'extension' => $file->extension,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
            ]);

            $this->quota->increment($user, $file->size);
        }

        foreach ($source->children()->whereNull('deleted_at')->get() as $child) {
            $this->duplicate($child, $user, $copy->id);
        }

        return $copy;
    }
}
