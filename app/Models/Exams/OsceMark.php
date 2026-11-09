<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;

/** One candidate's checklist at one station. */
class OsceMark extends Model
{
    protected $table = 'exam_osce_marks';

    protected $fillable = ['exam_osce_station_id', 'user_id', 'assessor_id', 'scores', 'global_rating', 'note', 'total'];

    protected function casts(): array
    {
        return ['scores' => 'array', 'total' => 'float', 'global_rating' => 'integer'];
    }
}
