<?php

namespace App\Services\Library;

use App\Models\ActivityLog;
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\Hold;
use App\Models\Library\LibraryMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reservations. A hold on a book with a free copy sets that copy aside at once; otherwise
 * the reader joins the queue and the next returned copy is set aside for the first in line.
 * A set-aside copy waits `hold_hours`, then goes to the next reader (or back on the shelf).
 */
class HoldService
{
    public function __construct(private LibraryRules $rules, private LibraryNotifier $notifier) {}

    public function place(LibraryMember $member, Book $book): Hold
    {
        return DB::transaction(function () use ($member, $book) {
            $fail = fn (string $m) => throw ValidationException::withMessages(['code' => $m]);
            $book->reference_only && $fail('هذا الكتاب للقراءة داخل المكتبة فقط.');
            ! $member->active && $fail('حساب القارئ موقوف.');
            Hold::open()->where('book_id', $book->id)->where('member_id', $member->id)->exists() && $fail('لديك حجز على هذا الكتاب بالفعل.');
            $member->activeLoans()->whereHas('copy', fn ($c) => $c->where('book_id', $book->id))->exists() && $fail('هذا الكتاب مُعار لك حالياً.');
            $book->copies()->count() === 0 && $fail('لا توجد نسخ من هذا الكتاب.');

            $hold = Hold::create(['book_id' => $book->id, 'member_id' => $member->id, 'status' => Hold::WAITING]);

            $free = $book->copies()->where('status', BookCopy::AVAILABLE)->lockForUpdate()->first();
            if ($free) {
                $this->setAside($hold, $free);
            }

            ActivityLog::record('library.hold', "{$member->name} حجز \"{$book->title}\"");

            return $hold->fresh();
        });
    }

    public function cancel(Hold $hold): void
    {
        DB::transaction(function () use ($hold) {
            $copy = $hold->status === Hold::READY ? $hold->copy : null;
            $hold->update(['status' => Hold::CANCELLED]);
            if ($copy) {
                $copy->update(['status' => $this->nextFor($copy)]);
            }
        });
    }

    /** Called when a copy comes back: returns the status the copy should take. */
    public function nextFor(BookCopy $copy): string
    {
        $next = Hold::where('book_id', $copy->book_id)->where('status', Hold::WAITING)->orderBy('id')->first();
        if (! $next) {
            return BookCopy::AVAILABLE;
        }

        $this->setAside($next, $copy);

        return BookCopy::ON_HOLD;
    }

    /** Ready holds whose time ran out pass the copy to the next reader. */
    public function expireOverdue(): int
    {
        $count = 0;
        Hold::where('status', Hold::READY)->where('expires_at', '<', now())->with('copy', 'member', 'book')->each(function (Hold $hold) use (&$count) {
            $hold->update(['status' => Hold::EXPIRED]);
            $this->notifier->notify($hold->member, 'hold_expired', 'انتهى حجزك',
                "انتهت مدة الاحتفاظ بكتاب \"{$hold->book?->title}\" ولم يُستلم، فأُلغي الحجز.");
            if ($hold->copy && $hold->copy->status === BookCopy::ON_HOLD) {
                $hold->copy->update(['status' => $this->nextFor($hold->copy)]);
            }
            $count++;
        });

        return $count;
    }

    private function setAside(Hold $hold, BookCopy $copy): void
    {
        $hours = $this->rules->general('hold_hours');
        $hold->update(['copy_id' => $copy->id, 'status' => Hold::READY, 'ready_at' => now(), 'expires_at' => now()->addHours($hours)]);
        $copy->update(['status' => BookCopy::ON_HOLD]);

        $hold->loadMissing('member', 'book');
        $this->notifier->notify($hold->member, 'hold_ready', 'كتابك المحجوز جاهز',
            "كتاب \"{$hold->book?->title}\" جاهز للاستلام من المكتبة (النسخة {$copy->barcode}). يُحتفظ به {$hours} ساعة، حتى ".$hold->expires_at->timezone('Asia/Baghdad')->format('Y-m-d H:i').' بتوقيت بغداد.');
    }
}
