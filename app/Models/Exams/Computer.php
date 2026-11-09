<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;

/** A computer in an exam hall, known by its fixed address on the hall's network. */
class Computer extends Model
{
    protected $table = 'exam_computers';

    protected $fillable = ['room', 'label', 'ip', 'seat_row', 'seat_col'];
}
