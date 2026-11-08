<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bookcase (or desk, table, wall, anti-theft gate, obstacle, hall/room, corridor, door, stairs) on the library
 * floor plan. Positions are in metres. Since 2026-11-06 (by request) a library can be several
 * halls and rooms in any direction: a «hall» is an outline (points) whose sides are walls, and a
 * «door» on a side lets readers walk through.
 */
class LibraryShelf extends Model
{
    /** Kinds drawn as an outline (points). */
    public const SHAPED = ['obstacle', 'hall', 'corridor'];

    public const KINDS = ['shelf', 'desk', 'table', 'wall', 'gate', 'obstacle', 'hall', 'corridor', 'door', 'stairs', 'qr'];

    protected $fillable = ['code', 'name', 'kind', 'storey', 'section_id', 'x', 'y', 'rotation', 'width', 'depth', 'levels', 'double_sided', 'color', 'points', 'height', 'locked'];

    protected function casts(): array
    {
        return [
            'x' => 'float', 'y' => 'float', 'width' => 'float', 'depth' => 'float',
            'rotation' => 'integer', 'storey' => 'integer', 'levels' => 'integer', 'double_sided' => 'boolean', 'locked' => 'boolean',
            'points' => 'array', 'height' => 'float',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(LibrarySection::class, 'section_id');
    }
}
