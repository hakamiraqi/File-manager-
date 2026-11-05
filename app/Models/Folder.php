<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id', 'parent_id', 'name', 'color', 'is_public', 'folder_type',
    'researcher_name', 'degree', 'supervisor_name', 'thesis_title', 'publisher', 'digital_library', 'publication_year',
    'university', 'college', 'department', 'subject', 'keywords', 'abstract', 'defense_date', 'language', 'pages', 'iqdr_receipt',
])]
class Folder extends Model
{
    use HasFactory, SoftDeletes;

    public const DEGREES = ['diploma', 'master', 'phd'];

    public const LANGUAGES = ['ar', 'en', 'ku', 'other'];

    /** The bibliographic fields of a thesis folder (the Iraqi Digital Repository record). */
    public const THESIS_FIELDS = [
        'folder_type', 'thesis_title', 'researcher_name', 'degree', 'supervisor_name',
        'university', 'college', 'department', 'subject', 'keywords', 'abstract',
        'publication_year', 'defense_date', 'language', 'pages', 'iqdr_receipt',
        'publisher', 'digital_library',
    ];

    /** Validation for those fields; shared by the web and API controllers. */
    public static function metadataRules(): array
    {
        $text = ['sometimes', 'nullable', 'string', 'max:255'];

        return [
            'folder_type' => ['sometimes', 'string', 'in:files,thesis'],
            'researcher_name' => $text,
            'degree' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::DEGREES)],
            'supervisor_name' => $text,
            'thesis_title' => $text,
            'publisher' => $text,
            'digital_library' => $text,
            'publication_year' => ['sometimes', 'nullable', 'string', 'max:20'],
            'university' => $text,
            'college' => $text,
            'department' => $text,
            'subject' => $text,
            'keywords' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'abstract' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'defense_date' => ['sometimes', 'nullable', 'date'],
            'language' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::LANGUAGES)],
            'pages' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'iqdr_receipt' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * A folder turned into a thesis takes the institution from the settings
     * (FileNest serves one college) unless it was typed.
     */
    public static function withInstitutionDefaults(array $data, ?self $folder = null): array
    {
        $isThesis = ($data['folder_type'] ?? $folder?->folder_type) === 'thesis';
        if (! $isThesis) {
            return $data;
        }
        foreach (['university' => 'institution_university', 'college' => 'institution_college'] as $field => $setting) {
            $current = array_key_exists($field, $data) ? $data[$field] : $folder?->{$field};
            if (blank($current) && filled($value = Setting::get($setting))) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'defense_date' => 'date:Y-m-d',
            'pages' => 'integer',
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

    /** Users an admin assigned this folder to. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'folder_user')->withTimestamps();
    }

    /**
     * True when the user owns this folder, is an admin, or was assigned this folder
     * or one of its ancestors (an assigned folder brings everything inside it).
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($user->isAdmin() || $this->user_id === $user->id) {
            return true;
        }

        $assigned = $user->assignedFolderIds();
        if ($assigned === []) {
            return false;
        }

        $node = $this;
        for ($depth = 0; $node !== null && $depth < 100; $depth++) {
            if (in_array($node->id, $assigned, true)) {
                return true;
            }
            $node = $node->parent;
        }

        return false;
    }

    /** The user's own root or the highest folder they were assigned: where their path starts. */
    public function breadcrumbsFor(User $user): array
    {
        $trail = $this->breadcrumbs();
        if ($user->isAdmin() || $this->user_id === $user->id) {
            return $trail;
        }

        $assigned = $user->assignedFolderIds();
        foreach ($trail as $i => $node) {
            if (in_array($node->id, $assigned, true)) {
                return array_slice($trail, $i);
            }
        }

        return $trail;
    }

    /**
     * Finds a folder the user may work in, or 404s (never 403, so ids of other
     * people's folders are not confirmed).
     */
    public static function findAccessible(User $user, int $id): self
    {
        $folder = static::whereNull('deleted_at')->findOrFail($id);
        abort_unless($folder->isAccessibleBy($user), 404);

        return $folder;
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
