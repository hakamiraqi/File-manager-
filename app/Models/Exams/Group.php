<?php

namespace App\Models\Exams;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A class or section of students; an exam given to groups is seen by their members only. */
class Group extends Model
{
    protected $table = 'exam_groups';

    protected $fillable = ['user_id', 'name', 'stage', 'department'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'exam_group_user', 'exam_group_id', 'user_id');
    }

    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(Exam::class, 'exam_exam_group', 'exam_group_id', 'exam_id');
    }

    /** Every teacher may give an exam to any group; its maker and admins change it. */
    public function isEditableBy(User $user): bool
    {
        return $user->isAdmin() || ($user->canManageExams() && $this->user_id === $user->id);
    }
}
