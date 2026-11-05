<?php

namespace App\Models\Library;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryMember extends Model
{
    public const TYPES = ['student', 'teacher', 'staff'];

    protected $fillable = ['user_id', 'name', 'card_number', 'type', 'stage', 'phone', 'email', 'active', 'notes'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // A reader added without a card number gets the next one: M00001, M00002...
        static::created(function (self $m) {
            if (str_starts_with($m->card_number, 'tmp-')) {
                $m->forceFill(['card_number' => sprintf('M%05d', $m->id)])->saveQuietly();
            }
        });
    }

    public static function newCardPlaceholder(): string
    {
        return 'tmp-'.\Illuminate\Support\Str::uuid();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'member_id');
    }

    public function activeLoans(): HasMany
    {
        return $this->loans()->whereNull('returned_at');
    }

    /** Unpaid fines from returned loans plus what the open ones owe so far. */
    public function finesDue(): int
    {
        $closed = (int) $this->loans()->whereNotNull('returned_at')->whereNull('fine_paid_at')->where('fine_waived', false)->sum('fine_amount');
        $open = $this->activeLoans()->get()->sum(fn (Loan $l) => $l->currentFine());

        return $closed + $open;
    }

    /** The reader record of a site user, created the first time it is needed. */
    public static function forUser(User $user): self
    {
        return self::firstOrCreate(['user_id' => $user->id], [
            'name' => $user->name,
            'email' => $user->email,
            'card_number' => self::newCardPlaceholder(),
            'type' => 'student',
        ]);
    }
}
