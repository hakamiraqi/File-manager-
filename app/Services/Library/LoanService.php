<?php

namespace App\Services\Library;

use App\Services\Notify;
use App\Models\ActivityLog;
use App\Models\Library\BookCopy;
use App\Models\Library\Hold;
use App\Models\Library\LibraryMember;
use App\Models\Library\Loan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Lending, returning and renewing, with the checks the counter and the reader pages share. */
class LoanService
{
    public function __construct(private LibraryRules $rules) {}

    public function issue(LibraryMember $member, BookCopy $copy, User $by): Loan
    {
        return DB::transaction(function () use ($member, $copy, $by) {
            $copy = BookCopy::lockForUpdate()->with('book')->findOrFail($copy->id);
            $rules = $this->rules->for($member->type);

            $this->fail(! $member->active, 'حساب القارئ موقوف.');
            $this->fail($copy->book?->reference_only, 'هذا الكتاب للقراءة داخل المكتبة فقط.');
            $this->fail($copy->status === BookCopy::LOANED, 'هذه النسخة مُعارة حالياً.');
            $this->fail(in_array($copy->status, [BookCopy::DAMAGED, BookCopy::LOST], true), 'هذه النسخة تالفة أو مفقودة.');
            $this->fail($copy->status === BookCopy::ON_HOLD && ! $this->holdIsFor($copy, $member), 'هذه النسخة محجوزة لقارئ آخر.');
            $this->fail($member->activeLoans()->count() >= $rules['max_loans'], "وصل القارئ للحد الأقصى ({$rules['max_loans']} كتب).");
            $this->fail($member->activeLoans()->where('due_at', '<', now()->startOfDay())->exists(), 'على القارئ كتب متأخرة، يجب إرجاعها أولاً.');
            $fines = $member->finesDue();
            $this->fail($fines > 0, 'على القارئ غرامة غير مدفوعة: '.number_format($fines).' د.ع');

            $loan = Loan::create([
                'copy_id' => $copy->id,
                'member_id' => $member->id,
                'issued_by' => $by->id,
                'issued_at' => now(),
                'due_at' => $this->rules->dueDate($member->type),
            ]);
            $copy->update(['status' => BookCopy::LOANED]);
            $this->afterIssue($copy, $member);

            ActivityLog::record('library.loan', "أعار \"{$copy->book?->title}\" ({$copy->barcode}) إلى {$member->name}");
            Notify::to($member->user, 'library', 'loan_issued', "استعرت «{$copy->book?->title}»",
                'موعد الإرجاع: '.$loan->due_at->timezone('Asia/Baghdad')->format('Y-m-d'), route('library.my-loans', [], false));

            return $loan->load('copy.book', 'member');
        });
    }

    public function receive(BookCopy $copy, User $by, string $condition = 'good'): Loan
    {
        return DB::transaction(function () use ($copy, $by, $condition) {
            $loan = Loan::where('copy_id', $copy->id)->whereNull('returned_at')->lockForUpdate()->first();
            $this->fail(! $loan, 'هذه النسخة ليست مُعارة.');

            $loan->load('member');
            $now = now();
            $days = $this->rules->lateDays($loan->due_at, $now->copy()->startOfDay());
            $fine = $days * $this->rules->for($loan->member->type)['fine_per_day'];

            $loan->update([
                'returned_at' => $now,
                'received_by' => $by->id,
                'fine_amount' => $fine,
                'return_condition' => $condition,
            ]);

            $status = $condition === 'damaged' ? BookCopy::DAMAGED : $this->statusAfterReturn($copy);
            $copy->update(['status' => $status, 'condition' => $condition === 'damaged' ? 'damaged' : $copy->condition]);

            ActivityLog::record('library.return', "استلم \"{$copy->book?->title}\" ({$copy->barcode}) من {$loan->member->name}");
            Notify::to($loan->member->user, 'library', $fine > 0 ? 'returned_fine' : 'returned', "تم استلام «{$copy->book?->title}»",
                $fine > 0 ? 'عليك غرامة تأخير: '.number_format($fine).' د.ع' : 'شكراً لإرجاع الكتاب في موعده.', route('library.my-loans', [], false));

            return $loan->fresh(['copy.book', 'member']);
        });
    }

    public function renew(Loan $loan): Loan
    {
        $loan->load('member', 'copy');
        $rules = $this->rules->for($loan->member->type);

        $this->fail($loan->returned_at !== null, 'الكتاب أُرجع بالفعل.');
        $this->fail($loan->is_overdue, 'لا يمكن تجديد كتاب متأخر، يجب إرجاعه.');
        $this->fail($loan->renewals >= $rules['renewals'], 'وصلت للحد الأقصى لمرات التجديد.');
        $this->fail($this->hasWaitingHolds($loan->copy), 'هذا الكتاب محجوز لقارئ آخر، لا يمكن تجديده.');

        $loan->update([
            'due_at' => $this->rules->dueDate($loan->member->type, $loan->due_at->copy()->startOfDay()),
            'renewals' => $loan->renewals + 1,
            'last_reminder_at' => null,
        ]);

        ActivityLog::record('library.renew', "جدّد إعارة {$loan->copy->barcode} لـ {$loan->member->name}");
        Notify::to($loan->member->user, 'library', 'renewed', 'تم تجديد إعارة كتاب',
            'موعد الإرجاع الجديد: '.$loan->due_at->timezone('Asia/Baghdad')->format('Y-m-d'), route('library.my-loans', [], false));

        return $loan;
    }

    /** A copy set aside goes only to the reader it was set aside for. */
    protected function holdIsFor(BookCopy $copy, LibraryMember $member): bool
    {
        return Hold::where('copy_id', $copy->id)->where('status', Hold::READY)->where('member_id', $member->id)->exists();
    }

    /** Someone is waiting for this book: renewing would keep them waiting. */
    protected function hasWaitingHolds(BookCopy $copy): bool
    {
        return Hold::where('book_id', $copy->book_id)->where('status', Hold::WAITING)->exists();
    }

    protected function statusAfterReturn(BookCopy $copy): string
    {
        return app(HoldService::class)->nextFor($copy);
    }

    /** Lending closes the reader's hold on that book. */
    protected function afterIssue(BookCopy $copy, LibraryMember $member): void
    {
        Hold::open()->where('book_id', $copy->book_id)->where('member_id', $member->id)
            ->update(['status' => Hold::FULFILLED, 'copy_id' => $copy->id]);
    }

    private function fail(bool $condition, string $message): void
    {
        if ($condition) {
            throw ValidationException::withMessages(['code' => $message]);
        }
    }
}
