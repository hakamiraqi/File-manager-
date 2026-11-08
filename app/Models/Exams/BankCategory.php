<?php

namespace App\Models\Exams;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A category of the question bank: its teacher's own, or shared with every teacher. */
class BankCategory extends Model
{
    protected $table = 'exam_bank_categories';

    protected $fillable = ['user_id', 'name', 'subject', 'is_shared'];

    protected function casts(): array
    {
        return ['is_shared' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'bank_category_id')->whereNull('exam_id')->orderBy('id');
    }

    /** Categories a teacher may use: their own and the shared ones (admins: all). */
    public function scopeUsableBy(Builder $q, User $user): Builder
    {
        return $user->isAdmin() ? $q : $q->where(fn ($w) => $w->where('user_id', $user->id)->orWhere('is_shared', true));
    }

    public function isUsableBy(User $user): bool
    {
        return $user->canManageExams() && ($user->isAdmin() || $this->user_id === $user->id || $this->is_shared);
    }

    public function isEditableBy(User $user): bool
    {
        return $user->canManageExams() && ($user->isAdmin() || $this->user_id === $user->id);
    }
}
