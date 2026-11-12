<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The signature a user draws once and places on PDFs in the reader (pen strokes, not an image). */
class ReaderSignature extends Model
{
    protected $fillable = ['user_id', 'strokes'];

    protected function casts(): array
    {
        return ['strokes' => 'array'];
    }
}
