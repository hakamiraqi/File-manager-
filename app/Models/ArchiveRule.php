<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One archive category (صادر، وارد، أمر إداري…) and the words in a document that point to it. */
#[Fillable(['name', 'keywords', 'match_mode', 'color', 'target_folder_id', 'priority', 'enabled'])]
class ArchiveRule extends Model
{
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'priority' => 'integer', 'target_folder_id' => 'integer'];
    }

    /** @return list<string> */
    public function keywordList(): array
    {
        return collect(preg_split('/[\n\r،,]+/u', (string) $this->keywords))
            ->map(fn ($k) => trim($k))->filter()->unique()->values()->all();
    }

    public function targetFolder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'target_folder_id');
    }

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'keywords' => ['required', 'string', 'max:5000'],
            'match_mode' => ['required', 'in:any,all'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'target_folder_id' => ['nullable', 'integer', 'exists:folders,id'],
            'priority' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
            'enabled' => ['boolean'],
        ];
    }
}
