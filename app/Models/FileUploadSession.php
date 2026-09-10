<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'folder_id', 'identifier', 'original_name', 'total_size', 'total_chunks', 'chunk_size', 'received_chunks', 'status'])]
class FileUploadSession extends Model
{
    protected function casts(): array
    {
        return [
            'received_chunks' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function isComplete(): bool
    {
        return count($this->received_chunks ?? []) >= $this->total_chunks;
    }

    public function chunkDir(): string
    {
        return "chunks/{$this->identifier}";
    }
}
