<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\Exams\Attempt;
use App\Models\Exams\Computer;
use App\Models\Exams\Event;
use App\Models\Exams\Exam;
use App\Models\User;
use App\Services\Exams\HallMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «اليوم» (2026-11-09, by request): the invigilator's one page for the day — today's exams with
 * their halls and times, who came, who started and handed in, what needs looking at now, and a
 * checklist per exam that ticks itself (published, questions, groups, review, the hall's computers
 * on and their clocks right, students seated…).
 */
class TodayController extends Controller
{
    public function __construct(private HallMap $map) {}

    public function show(Request $request): Response|JsonResponse
    {
        $data = $this->data($request->user());

        return $request->wantsJson() ? response()->json($data) : Inertia::render('Exams/Monitor/Today', $data);
    }

    /** @return array{date: string, exams: array, halls: array} */
    public function data(User $user): array
    {
        $start = now()->startOfDay();
        $end = now()->endOfDay();
        $exams = Exam::published()->with('groups:id,name')
            ->when(! $user->isAdmin() && ! $user->is_invigilator, fn ($q) => $q->where('user_id', $user->id))
            ->where('is_assignment', false)
            ->latest('id')->limit(300)->get()
            ->filter(fn (Exam $e) => $this->isToday($e, $start, $end))->values();

        $live = $this->map->live()['computers'];
        $pcs = Computer::all();

        return [
            'date' => now()->toIso8601String(),
            'exams' => $exams->map(fn (Exam $e) => $this->examDay($e, $pcs, $live))->sortBy('starts')->values()->all(),
            'halls' => $pcs->groupBy(fn ($pc) => $pc->room ?: '—')->map(fn ($list, $room) => [
                'room' => $room, 'total' => $list->count(),
                'on' => $list->filter(fn ($pc) => in_array($live[$pc->id]['state'] ?? 'off', ['on', 'waiting', 'working', 'submitted', 'help', 'suspect', 'wrong_pc', 'time_up'], true))->count(),
                'problems' => $list->filter(fn ($pc) => ! empty($live[$pc->id]['problems']))->count(),
            ])->values()->all(),
        ];
    }

    /** Opens today, a sitting today, or students working on it now. */
    private function isToday(Exam $e, Carbon $start, Carbon $end): bool
    {
        if ($e->opens_at && $e->opens_at->between($start, $end)) {
            return true;
        }
        foreach ((array) $e->sessions as $s) {
            if (! empty($s['opens_at']) && Carbon::parse($s['opens_at'])->between($start, $end)) {
                return true;
            }
        }

        return $e->attempts()->whereNull('submitted_at')->where('started_at', '>=', $start)->exists();
    }

    private function examDay(Exam $e, $pcs, array $live): array
    {
        $roster = $e->groups->isNotEmpty() ? $e->roster() : collect();
        $expected = $roster->count();
        $attendance = DB::table('exam_attendance')->where('exam_id', $e->id)->get(['user_id', 'present', 'computer_id']);
        $attempts = Attempt::where('exam_id', $e->id)->get(['id', 'user_id', 'submitted_at', 'locked_at', 'help_requested_at']);
        $running = $attempts->whereNull('submitted_at');
        $suspects = $running->isEmpty() ? 0 : Event::whereIn('exam_attempt_id', $running->pluck('id'))
            ->where('created_at', '>=', now()->subMinutes(HallMap::SUSPECT_MINUTES))->where('type', '!=', 'offline_file')->distinct()->count('exam_attempt_id');
        $sittings = collect((array) $e->sessions)->filter(fn ($s) => ! empty($s['opens_at']) && Carbon::parse($s['opens_at'])->isToday())
            ->map(fn ($s) => ['label' => $s['label'] ?? '', 'opens_at' => $s['opens_at'], 'closes_at' => $s['closes_at'] ?? null])->values();
        $starts = $sittings->first()['opens_at'] ?? $e->opens_at?->toIso8601String();

        // The hall: its computers (by the exam's hall name), how many are on, and their clocks.
        $hallPcs = $e->hall ? $pcs->where('room', $e->hall) : collect();
        $on = $hallPcs->filter(fn ($pc) => ($live[$pc->id]['linked'] ?? false));
        $badClocks = $on->filter(fn ($pc) => ($live[$pc->id]['sync'] ?? null) === 'wrong')->count();
        $seated = $attendance->whereNotNull('computer_id')->count();
        $questions = $e->is_osce ? $e->osceStations()->count() : $e->questions()->count();
        $minutesTo = $starts ? now()->diffInMinutes(Carbon::parse($starts), false) : null;

        $check = [
            ['key' => 'published', 'ok' => true, 'label' => __('الامتحان منشور')],
            ['key' => 'questions', 'ok' => $questions > 0, 'label' => $e->is_osce ? __('محطات الامتحان العملي') : __('الأسئلة'), 'detail' => (string) $questions, 'url' => route('exams.manage.edit', $e)],
            ['key' => 'review', 'ok' => ! $e->awaitsReview(), 'label' => __('موافقة المراجع'), 'detail' => $e->reviewer_id ? ($e->awaitsReview() ? __('بانتظار المراجعة') : __('موافَق عليه')) : __('بلا مراجع')],
            ['key' => 'groups', 'ok' => $e->groups->isNotEmpty() ? true : null, 'label' => __('المجموعات'),
                'detail' => $e->groups->isNotEmpty() ? $e->groups->pluck('name')->implode('، ').' · '.$expected.' '.__('طالب') : __('بلا مجموعة: يراه كل الطلاب'), 'url' => route('exams.manage.edit', $e)],
            ['key' => 'time', 'ok' => $starts ? true : null, 'label' => __('موعد الفتح'), 'detail' => $starts ? Carbon::parse($starts)->format('H:i') : __('مفتوح بلا موعد')],
            ['key' => 'hall', 'ok' => $e->hall ? ($hallPcs->isNotEmpty() ? true : false) : null, 'label' => __('القاعة وحاسباتها'),
                'detail' => $e->hall ? $e->hall.' · '.$hallPcs->count().' '.__('حاسبة') : __('لم تُحدَّد قاعة'), 'url' => route('exams.monitor.computers')],
        ];
        if ($e->hall && $hallPcs->isNotEmpty()) {
            $check[] = ['key' => 'computers_on', 'ok' => $on->count() >= min($hallPcs->count(), max(1, $expected)) ? true : ($on->isEmpty() ? false : null),
                'label' => __('الحاسبات شغّالة ومتصلة'), 'detail' => $on->count().' / '.$hallPcs->count(), 'url' => route('exams.monitor.halls')];
            $check[] = ['key' => 'clocks', 'ok' => $on->isEmpty() ? null : $badClocks === 0, 'label' => __('ساعات الحاسبات مضبوطة'),
                'detail' => $badClocks ? $badClocks.' '.__('غير مضبوطة') : __('كلها متزامنة'), 'url' => route('exams.monitor.halls')];
        }
        if ($e->bind_computers) {
            $check[] = ['key' => 'seats', 'ok' => $expected && $seated >= $expected ? true : ($seated ? null : false), 'label' => __('الطلاب مربوطون بحاسباتهم'),
                'detail' => $seated.' / '.($expected ?: '—'), 'url' => route('exams.monitor.show', $e)];
        }
        if ($e->access_code) {
            $check[] = ['key' => 'code', 'ok' => true, 'label' => __('رمز الدخول جاهز'), 'detail' => $e->access_code];
        }

        return [
            'id' => $e->id, 'title' => $e->text('title'), 'subject' => $e->subject, 'hall' => $e->hall,
            'groups' => $e->groups->pluck('name'), 'starts' => $starts, 'closes' => $sittings->first()['closes_at'] ?? $e->closes_at?->toIso8601String(),
            'duration' => $e->duration_minutes, 'sittings' => $sittings, 'minutes_to' => $minutesTo,
            'state' => $running->isNotEmpty() ? 'running' : ($minutesTo !== null && $minutesTo > 0 ? 'soon' : ($attempts->isNotEmpty() ? 'done' : 'open')),
            'expected' => $expected, 'present' => $attendance->where('present', true)->count(),
            'started' => $attempts->count(), 'running' => $running->count(), 'submitted' => $attempts->whereNotNull('submitted_at')->count(),
            'locked' => $running->whereNotNull('locked_at')->count(), 'help' => $running->whereNotNull('help_requested_at')->count(), 'suspects' => $suspects,
            'check' => $check, 'ready' => collect($check)->every(fn ($c) => $c['ok'] !== false),
        ];
    }
}
