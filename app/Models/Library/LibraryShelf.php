<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bookcase (or desk, table, wall, anti-theft gate, obstacle) on the library floor plan. Positions are in metres. */
class LibraryShelf extends Model
{
    public const KINDS = ['shelf', 'desk', 'table', 'wall', 'gate', 'obstacle'];

    protected $fillable = ['code', 'name', 'kind', 'section_id', 'x', 'y', 'rotation', 'width', 'depth', 'levels', 'double_sided', 'color', 'points', 'height'];

    protected function casts(): array
    {
        return [
            'x' => 'float', 'y' => 'float', 'width' => 'float', 'depth' => 'float',
            'rotation' => 'integer', 'levels' => 'integer', 'double_sided' => 'boolean',
            'points' => 'array', 'height' => 'float',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(LibrarySection::class, 'section_id');
    }
}
