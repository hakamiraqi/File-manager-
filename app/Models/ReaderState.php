<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One reader's place, bookmarks and notes on one file (the PDF reader). */
class ReaderState extends Model
{
    protected $fillable = ['user_id', 'file_id', 'last_page', 'pages', 'bookmarks', 'annotations'];

    protected function casts(): array
    {
        return ['bookmarks' => 'array', 'annotations' => 'array', 'last_page' => 'integer', 'pages' => 'integer'];
    }
}
