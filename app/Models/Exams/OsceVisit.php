<?php

namespace App\Models\Exams;

use Illuminate\Database\Eloquent\Model;

/** One candidate at one station: when they came in and when the assessor finished. */
class OsceVisit extends Model
{
    protected $table = 'exam_osce_visits';

    protected $fillable = ['exam_osce_station_id', 'user_id', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** Seconds spent at the station (so far, while still there); null when the start was not recorded. */
    public function seconds(): ?int
    {
        return $this->started_at ? (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), true) : null;
    }
}
