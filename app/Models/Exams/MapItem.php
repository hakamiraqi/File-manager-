<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something on the halls' plan (from FileNest's LibraryShelf): a computer («pc», linked to its
 * row in exam_computers), a desk, a table, a wall, an obstacle, a hall or corridor (an outline),
 * a door, stairs or a lift, or a QR sticker on a wall. Positions are in metres.
 */
class MapItem extends Model
{
    protected $table = 'exam_map_items';

    /** Kinds drawn as an outline (points). */
    public const SHAPED = ['obstacle', 'hall', 'corridor'];

    public const KINDS = ['pc', 'desk', 'table', 'wall', 'gate', 'obstacle', 'hall', 'corridor', 'door', 'stairs', 'qr'];

    protected $fillable = ['code', 'name', 'kind', 'storey', 'computer_id', 'x', 'y', 'rotation', 'width', 'depth', 'levels', 'double_sided', 'color', 'points', 'height', 'locked'];

    protected function casts(): array
    {
        return [
            'x' => 'float', 'y' => 'float', 'width' => 'float', 'depth' => 'float',
            'rotation' => 'integer', 'storey' => 'integer', 'levels' => 'integer', 'double_sided' => 'boolean', 'locked' => 'boolean',
            'points' => 'array', 'height' => 'float', 'computer_id' => 'integer',
        ];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }
}
