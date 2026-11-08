<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['cpu', 'ram_free_mb', 'disk_free_gb', 'active_users', 'state', 'reason', 'processed', 'created_at'])]
class OcrLoadSample extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'cpu' => 'float', 'disk_free_gb' => 'float'];
    }
}
