<?php

namespace App\Models\Library;

use App\Models\User;
use App\Services\Library\LibraryRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    protected $table = 'library_loans';

    protected $fillable = [
        'copy_id', 'member_id', 'issued_by', 'received_by', 'issued_at', 'due_at', 'returned_at', 'renewals',
        'fine_amount', 'fine_paid_at', 'fine_waived', 'return_condition', 'last_reminder_at',
    ];

    protected $appends = ['is_overdue', 'days_overdue'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime', 'due_at' => 'datetime', 'returned_at' => 'datetime',
            'fine_paid_at' => 'datetime', 'last_reminder_at' => 'datetime', 'fine_waived' => 'boolean',
        ];
    }

    public function copy(): BelongsTo
    {
        return $this->belongsTo(BookCopy::class, 'copy_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(LibraryMember::class, 'member_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function scopeActive($q)
    {
        return $q->whereNull('returned_at');
    }

    public function scopeOverdue($q)
    {
        return $q->whereNull('returned_at')->where('due_at', '<', now()->startOfDay());
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->returned_at === null && $this->due_at->lt(now()->startOfDay());
    }

    /** Late days (open loans: until today; returned: until the return day), holidays not counted. */
    public function getDaysOverdueAttribute(): int
    {
        $end = ($this->returned_at ?? now())->copy()->startOfDay();

        return app(LibraryRules::class)->lateDays($this->due_at, $end);
    }

    /** The fine this loan owes now (stored once returned, computed while open). */
    public function currentFine(): int
    {
        if ($this->returned_at) {
            return $this->fine_waived || $this->fine_paid_at ? 0 : (int) $this->fine_amount;
        }

        return $this->days_overdue * app(LibraryRules::class)->for($this->member?->type ?? 'student')['fine_per_day'];
    }
}
