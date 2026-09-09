<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'parent_id', 'name', 'color', 'is_public'])]
class Folder extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /**
     * True if this folder or any ancestor is flagged public, which is what
     * makes it (and everything under it) reachable by unauthenticated guest
     * browsing when the "guest_access_enabled" setting is on.
     */
    public function isPubliclyReachable(): bool
    {
        $node = $this;

        while ($node !== null) {
            if ($node->is_public) {
                return true;
            }
            $node = $node->parent;
        }

        return false;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function shares(): MorphMany
    {
        return $this->morphMany(Share::class, 'shareable');
    }

    /**
     * Breadcrumb trail from root to this folder, root first.
     *
     * @return array<int, Folder>
     */
    public function breadcrumbs(): array
    {
        $trail = [];
        $node = $this;

        while ($node !== null) {
            array_unshift($trail, $node);
            $node = $node->parent;
        }

        return $trail;
    }

    /**
     * All descendant folder ids (including this folder's id), used to
     * compute total size / cascade a move-to-trash to sub-folders.
     */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $queue = [$this->id];

        while ($queue !== []) {
            $childIds = Folder::where('parent_id', array_shift($queue))->pluck('id')->all();
            foreach ($childIds as $childId) {
                $ids[] = $childId;
                $queue[] = $childId;
            }
        }

        return $ids;
    }
}
