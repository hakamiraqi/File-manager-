<?php

namespace App\Services\Library;

use App\Models\Library\Hold;
use App\Models\Library\Loan;

/**
 * The daily reminders (run at 09:00 Baghdad): before the due date, on the day, and every
 * few days while late; plus a summary for library staff.
 */
class ReminderService
{
    public function __construct(private LibraryRules $rules, private LibraryNotifier $notifier) {}

    /** @return array{before:int, today:int, late:int} */
    public function run(): array
    {
        $today = now()->startOfDay();
        $before = $this->rules->general('remind_days_before');
        $every = max(1, $this->rules->general('remind_every_days'));
        $sent = ['before' => 0, 'today' => 0, 'late' => 0];

        Loan::active()->with('member.user', 'copy.book')->each(function (Loan $loan) use ($today, $before, $every, &$sent) {
            if (! $loan->member) {
                return;
            }
            $due = $loan->due_at->copy()->startOfDay();
            $daysLeft = (int) $today->diffInDays($due, false);
            $title = $loan->copy?->book?->title;
            $date = $loan->due_at->format('Y-m-d');
            $remindedToday = $loan->last_reminder_at && $loan->last_reminder_at->isSameDay(now());

            if ($remindedToday) {
                return;
            }

            if ($before > 0 && $daysLeft === $before) {
                $this->notifier->notify($loan->member, 'due_soon', 'تذكير بموعد إرجاع كتاب',
                    "موعد إرجاع كتاب \"{$title}\" بعد {$before} أيام ({$date}). يمكنك تجديده من صفحة «استعاراتي» إن لم يكن محجوزاً.");
                $sent['before']++;
            } elseif ($daysLeft === 0) {
                $this->notifier->notify($loan->member, 'due_today', 'اليوم موعد إرجاع كتاب', "اليوم آخر موعد لإرجاع كتاب \"{$title}\".");
                $sent['today']++;
            } elseif ($daysLeft < 0) {
                $lastLate = $loan->last_reminder_at && $loan->last_reminder_at->gt($loan->due_at) ? $loan->last_reminder_at : null;
                if ($lastLate && $lastLate->copy()->startOfDay()->diffInDays($today) < $every) {
                    return;
                }
                $fine = $loan->currentFine();
                $this->notifier->notify($loan->member, 'overdue', 'كتاب متأخر عن موعد الإرجاع',
                    "كتاب \"{$title}\" متأخر {$loan->days_overdue} يوم (كان موعده {$date})."
                    .($fine ? ' الغرامة حتى الآن: '.number_format($fine).' د.ع.' : '').' الرجاء إرجاعه للمكتبة.');
                $sent['late']++;
            } else {
                return;
            }

            $loan->forceFill(['last_reminder_at' => now()])->saveQuietly();
        });

        $overdue = Loan::overdue()->count();
        $ready = Hold::where('status', Hold::READY)->count();
        if ($overdue || $ready) {
            $this->notifier->staff('ملخص المكتبة اليومي',
                "الكتب المتأخرة: {$overdue}\nالكتب المحجوزة الجاهزة للاستلام: {$ready}\nالتفاصيل في صفحة «الاستعارات».");
        }

        return $sent;
    }
}
