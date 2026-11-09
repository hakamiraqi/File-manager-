<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;

/** A computer in an exam hall, known by its fixed address on the hall's network. */
class Computer extends Model
{
    protected $table = 'exam_computers';

    protected $fillable = ['room', 'label', 'ip', 'seat_row', 'seat_col'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'last_clock_offset' => 'integer'];
    }

    /** Seconds without a sign of life before the map shows a computer as off. */
    public const QUIET_SECONDS = 75;
}
