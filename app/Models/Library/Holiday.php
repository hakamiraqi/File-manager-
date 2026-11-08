<?php

namespace App\Models\Library;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $table = 'library_holidays';

    protected $fillable = ['date', 'name'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }
}
