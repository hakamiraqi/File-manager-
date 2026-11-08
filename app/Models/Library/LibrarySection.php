<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibrarySection extends Model
{
    protected $fillable = ['parent_id', 'name', 'description', 'location', 'cover_path', 'sort'];

    protected $appends = ['cover_url'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class, 'section_id');
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover_path ? route('library.cover', ['type' => 'section', 'id' => $this->id, 'v' => $this->updated_at?->timestamp]) : null;
    }

    /** "الدراسات الأولية / جراحة الفم" */
    public function path(): string
    {
        $parts = [];
        for ($n = $this, $i = 0; $n && $i < 20; $n = $n->parent, $i++) {
            array_unshift($parts, $n->name);
        }

        return implode(' / ', $parts);
    }

    /** This section and every section under it. */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $queue = [$this->id];
        while ($queue !== []) {
            foreach (self::where('parent_id', array_shift($queue))->pluck('id') as $id) {
                $ids[] = $id;
                $queue[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Every section with its full path, for pickers and filters.
     *
     * @return array<int, array{id:int, parent_id:?int, name:string, path:string}>
     */
    public static function options(): array
    {
        $all = self::orderBy('sort')->orderBy('name')->get(['id', 'parent_id', 'name'])->keyBy('id');

        return $all->map(function ($s) use ($all) {
            $parts = [];
            for ($n = $s, $i = 0; $n && $i < 20; $n = $all->get($n->parent_id), $i++) {
                array_unshift($parts, $n->name);
            }

            return ['id' => $s->id, 'parent_id' => $s->parent_id, 'name' => $s->name, 'path' => implode(' / ', $parts)];
        })->sortBy('path', SORT_NATURAL)->values()->all();
    }

    /** Finds or creates the section for a path typed in an import ("A / B"). */
    public static function fromPath(string $path): ?self
    {
        $parent = null;
        foreach (array_filter(array_map('trim', preg_split('#\s*[/\\\\>]\s*#u', $path))) as $name) {
            $parent = self::firstOrCreate(['parent_id' => $parent?->id, 'name' => $name]);
        }

        return $parent;
    }
}
