<?php

namespace App\Models\Exams;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One station of a practical exam: a task, its time, and the checklist its assessor marks. */
class OsceStation extends Model
{
    protected $table = 'exam_osce_stations';

    protected $fillable = ['exam_id', 'position', 'name', 'minutes', 'checklist', 'assessor_id'];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'minutes' => 'integer', 'position' => 'integer'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }

    public function marks(): HasMany
    {
        return $this->hasMany(OsceMark::class, 'exam_osce_station_id');
    }

    public function maxScore(): float
    {
        return (float) collect($this->checklist)->sum(fn ($i) => (float) ($i['points'] ?? 0));
    }

    /** The station's assessor, or the exam's owner (or an admin). */
    public function isMarkableBy(User $user): bool
    {
        return $this->assessor_id === $user->id || $this->exam->isManageableBy($user);
    }
}
