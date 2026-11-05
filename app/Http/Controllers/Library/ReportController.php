<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\LibraryMember;
use App\Models\Library\LibrarySection;
use App\Models\Library\Loan;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Library numbers for a period: activity by month, most borrowed, readers, sections, fines, losses. */
class ReportController extends Controller
{
    private const TZ = 'Asia/Baghdad';

    public function index(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $loans = $this->loansIn($from, $to)->with(['copy.book:id,title,authors,section_id', 'member:id,name,type,card_number'])->get();
        $returns = Loan::whereBetween('returned_at', [$from->utc(), $to->utc()])->count();
        $paid = Loan::whereBetween('fine_paid_at', [$from->utc(), $to->utc()])->where('fine_waived', false);

        return Inertia::render('Library/Manage/Reports', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'loans' => $loans->count(),
                'returns' => $returns,
                'readers' => $loans->pluck('member_id')->unique()->count(),
                'titles' => $loans->pluck('copy.book_id')->unique()->count(),
                'overdue_now' => Loan::overdue()->count(),
                'active_now' => Loan::active()->count(),
                'fines_collected' => (int) $paid->sum('fine_amount'),
                'fines_waived' => (int) Loan::whereBetween('updated_at', [$from->utc(), $to->utc()])->where('fine_waived', true)->sum('fine_amount'),
                'fines_due' => (int) Loan::where('fine_amount', '>', 0)->whereNull('fine_paid_at')->where('fine_waived', false)->sum('fine_amount'),
                'lost' => BookCopy::where('status', BookCopy::LOST)->count(),
                'damaged' => BookCopy::where('status', BookCopy::DAMAGED)->count(),
                'books' => Book::count(),
                'copies' => BookCopy::count(),
                'members' => LibraryMember::count(),
            ],
            'monthly' => $this->monthly($from, $to),
            'topBooks' => $loans->groupBy('copy.book_id')
                ->map(fn (Collection $g) => ['id' => $g->first()->copy?->book?->id, 'title' => $g->first()->copy?->book?->title, 'authors' => $g->first()->copy?->book?->authors, 'count' => $g->count()])
                ->sortByDesc('count')->take(10)->values(),
            'topReaders' => $loans->groupBy('member_id')
                ->map(fn (Collection $g) => ['name' => $g->first()->member?->name, 'card' => $g->first()->member?->card_number, 'count' => $g->count()])
                ->sortByDesc('count')->take(10)->values(),
            'bySection' => $this->bySection($loans),
            'byType' => $loans->groupBy(fn ($l) => $l->member?->type ?? 'student')->map->count(),
            'lateReturns' => Loan::whereBetween('returned_at', [$from->utc(), $to->utc()])->whereColumn('returned_at', '>', 'due_at')->count(),
        ]);
    }

    /** All loans issued in the period as a CSV (opens in Excel with Arabic intact). */
    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $loans = $this->loansIn($from, $to)->with(['copy.book:id,title,authors,section_id', 'member:id,name,type,card_number'])->orderBy('issued_at')->get();
        $paths = collect(LibrarySection::options())->pluck('path', 'id');
        $date = fn ($d) => $d ? $d->copy()->timezone(self::TZ)->format('Y-m-d H:i') : '';

        return response()->streamDownload(function () use ($loans, $paths, $date) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['الكتاب', 'المؤلف', 'القسم', 'الباركود', 'القارئ', 'رقم البطاقة', 'تاريخ الإعارة', 'موعد الإرجاع', 'تاريخ الإرجاع', 'التجديدات', 'الغرامة', 'حالة الغرامة']);
            foreach ($loans as $l) {
                fputcsv($out, [
                    $l->copy?->book?->title, $l->copy?->book?->authors, $paths[$l->copy?->book?->section_id] ?? '',
                    $l->copy?->barcode, $l->member?->name, $l->member?->card_number,
                    $date($l->issued_at), $date($l->due_at), $date($l->returned_at), $l->renewals, $l->fine_amount,
                    $l->fine_amount ? ($l->fine_waived ? 'معفاة' : ($l->fine_paid_at ? 'مدفوعة' : 'غير مدفوعة')) : '',
                ]);
            }
            fclose($out);
        }, "library-loans-{$from->toDateString()}_{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} start/end of day in Baghdad time */
    private function period(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $to = $request->filled('to') ? CarbonImmutable::parse($request->input('to'), self::TZ) : CarbonImmutable::now(self::TZ);
        $from = $request->filled('from') ? CarbonImmutable::parse($request->input('from'), self::TZ) : $to->subMonths(11)->startOfMonth();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    private function loansIn(CarbonImmutable $from, CarbonImmutable $to)
    {
        return Loan::whereBetween('issued_at', [$from->utc(), $to->utc()]);
    }

    /** Loans and returns per month, counted in PHP so it works on SQLite and MySQL alike. */
    private function monthly(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        for ($m = $from->startOfMonth(); $m->lte($to) && count($months) < 36; $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'loans' => 0, 'returns' => 0];
        }
        $bucket = fn ($d) => \Illuminate\Support\Carbon::parse($d)->timezone(self::TZ)->format('Y-m');

        foreach ($this->loansIn($from, $to)->pluck('issued_at') as $d) {
            isset($months[$bucket($d)]) && $months[$bucket($d)]['loans']++;
        }
        foreach (Loan::whereBetween('returned_at', [$from->utc(), $to->utc()])->pluck('returned_at') as $d) {
            isset($months[$bucket($d)]) && $months[$bucket($d)]['returns']++;
        }

        return array_values($months);
    }

    /** Loans per top-level section (sub-sections roll up). */
    private function bySection(Collection $loans): array
    {
        $parents = LibrarySection::pluck('parent_id', 'id');
        $names = LibrarySection::pluck('name', 'id');
        $root = function ($id) use ($parents) {
            for ($i = 0; $id && ($parents[$id] ?? null) && $i < 20; $i++) {
                $id = $parents[$id];
            }

            return $id;
        };

        return $loans->groupBy(fn ($l) => $root($l->copy?->book?->section_id) ?: 0)
            ->map(fn ($g, $id) => ['name' => $id ? $names[$id] : 'بدون قسم', 'count' => $g->count()])
            ->sortByDesc('count')->values()->all();
    }
}
