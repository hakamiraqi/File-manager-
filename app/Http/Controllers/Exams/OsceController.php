<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\OsceMark;
use App\Models\Exams\OsceStation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Practical / clinical exams (OSCE): candidates rotate through stations; at each one an
 * assessor marks a checklist on their own device. Each candidate's total is kept as their
 * attempt, so the gradebook, grade sheet and results show it like any exam.
 */
class OsceController extends Controller
{
    /** The teacher's page: stations, the rotation and the results. */
    public function manage(Request $request, Exam $exam): Response
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        $stations = $exam->osceStations()->with('assessor:id,name,email')->get();
        $candidates = $exam->roster()->values();
        $marks = OsceMark::whereIn('exam_osce_station_id', $stations->pluck('id'))->get()->groupBy('user_id');

        return Inertia::render('Exams/Osce/Manage', [
            'exam' => ['id' => $exam->id, 'title' => $exam->text('title')],
            'stations' => $stations->map(fn (OsceStation $s) => ['id' => $s->id, 'name' => $s->name, 'minutes' => $s->minutes, 'checklist' => $s->checklist,
                'assessor_email' => $s->assessor?->email, 'assessor' => $s->assessor?->name, 'max' => $s->maxScore()]),
            'candidates' => $candidates->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'number' => $u->student_number,
                'marks' => $stations->mapWithKeys(fn ($s) => [$s->id => ($m = ($marks[$u->id] ?? collect())->firstWhere('exam_osce_station_id', $s->id))
                    ? ['total' => $m->total, 'global' => $m->global_rating] : null])]),
            'max' => $stations->sum(fn ($s) => $s->maxScore()),
        ]);
    }

    /** Saves the stations (name, time, checklist, assessor), in order. */
    public function saveStations(Request $request, Exam $exam): RedirectResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        $data = $request->validate([
            'stations' => ['array', 'max:30'],
            'stations.*.id' => ['nullable', 'integer'],
            'stations.*.name' => ['required', 'string', 'max:150'],
            'stations.*.minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'stations.*.assessor_email' => ['nullable', 'email'],
            'stations.*.checklist' => ['required', 'array', 'min:1', 'max:60'],
            'stations.*.checklist.*.item' => ['required', 'string', 'max:300'],
            'stations.*.checklist.*.points' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        DB::transaction(function () use ($exam, $data) {
            $keep = [];
            foreach ($data['stations'] ?? [] as $i => $s) {
                $assessor = filled($s['assessor_email'] ?? null) ? User::where('email', mb_strtolower(trim($s['assessor_email'])))->first() : null;
                if (filled($s['assessor_email'] ?? null) && (! $assessor || ! ($assessor->canManageExams() || $assessor->is_invigilator))) {
                    throw ValidationException::withMessages(["stations.$i.assessor_email" => 'المقيِّم يجب أن يكون أستاذاً أو مراقباً له حساب.']);
                }
                $station = isset($s['id']) ? $exam->osceStations()->whereKey($s['id'])->first() : null;
                $station ??= new OsceStation(['exam_id' => $exam->id]);
                $station->fill(['position' => $i, 'name' => $s['name'], 'minutes' => $s['minutes'], 'assessor_id' => $assessor?->id,
                    'checklist' => collect($s['checklist'])->map(fn ($c) => ['item' => $c['item'], 'points' => (float) $c['points']])->values()->all()])->save();
                $keep[] = $station->id;
            }
            $exam->osceStations()->whereNotIn('id', $keep)->delete();
            $exam->forceFill(['is_osce' => true])->save();
        });
        $exam->attempts()->get()->each(fn (Attempt $a) => self::syncAttempt($exam, $a->user_id));

        return back()->with('success', 'حُفظت المحطات.');
    }

    /** The assessor's list: the stations they mark (all of an exam's for its owner). */
    public function mine(Request $request): Response
    {
        $me = $request->user();
        $stations = OsceStation::with('exam:id,title,user_id,is_osce')->where('assessor_id', $me->id)
            ->orWhereHas('exam', fn ($q) => $q->where('user_id', $me->id))->get()->filter(fn ($s) => $s->exam?->is_osce);

        return Inertia::render('Exams/Osce/Mine', [
            'stations' => $stations->map(fn (OsceStation $s) => ['id' => $s->id, 'name' => $s->name, 'exam' => $s->exam->text('title'),
                'marked' => $s->marks()->count()])->values(),
        ]);
    }

    /** One station on the assessor's device: the candidates, and the checklist for each. */
    public function station(Request $request, OsceStation $station): Response
    {
        abort_unless($station->isMarkableBy($request->user()), 404);
        $exam = $station->exam;
        $marks = $station->marks()->get()->keyBy('user_id');

        return Inertia::render('Exams/Osce/Station', [
            'station' => ['id' => $station->id, 'name' => $station->name, 'minutes' => $station->minutes, 'checklist' => $station->checklist, 'max' => $station->maxScore(),
                'exam' => $exam->text('title')],
            'candidates' => $exam->roster()->values()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'number' => $u->student_number, 'photo' => $u->photoUrl(),
                'mark' => ($m = $marks[$u->id] ?? null) ? ['scores' => $m->scores, 'global' => $m->global_rating, 'note' => $m->note, 'total' => $m->total] : null]),
        ]);
    }

    /** The assessor's checklist for one candidate (saved again and again until the end). */
    public function mark(Request $request, OsceStation $station, User $user): JsonResponse
    {
        abort_unless($station->isMarkableBy($request->user()), 404);
        abort_unless($station->exam->roster()->contains('id', $user->id), 422, 'ليس من مرشّحي هذا الامتحان.');
        $data = $request->validate(['scores' => ['required', 'array'], 'scores.*' => ['nullable', 'numeric', 'min:0'],
            'global_rating' => ['nullable', 'integer', 'min:1', 'max:5'], 'note' => ['nullable', 'string', 'max:2000']]);
        // Each item at most its points.
        $scores = collect($station->checklist)->map(fn ($c, $i) => min((float) ($c['points'] ?? 0), max(0, (float) ($data['scores'][$i] ?? 0))))->values();
        OsceMark::updateOrCreate(['exam_osce_station_id' => $station->id, 'user_id' => $user->id], [
            'assessor_id' => $request->user()->id, 'scores' => $scores->all(), 'global_rating' => $data['global_rating'] ?? null,
            'note' => $data['note'] ?? null, 'total' => round($scores->sum(), 2),
        ]);
        $attempt = self::syncAttempt($station->exam, $user->id);
        ActivityLog::record('exam.osce_mark', "قيّم {$user->name} في محطة \"{$station->name}\"", $station->exam);

        return response()->json(['total' => round($scores->sum(), 2), 'exam_total' => $attempt->score]);
    }

    /** The candidate's total over all stations, kept as their attempt (unfinished until every station marked). */
    public static function syncAttempt(Exam $exam, int $userId): Attempt
    {
        $stations = $exam->osceStations()->get();
        $marks = OsceMark::whereIn('exam_osce_station_id', $stations->pluck('id'))->where('user_id', $userId)->get();
        $attempt = Attempt::firstOrNew(['exam_id' => $exam->id, 'user_id' => $userId, 'number' => 1]);
        $attempt->fill([
            'started_at' => $attempt->started_at ?? now(),
            'submitted_at' => $attempt->submitted_at ?? now(),
            'score' => round($marks->sum('total'), 2),
            'max_score' => $stations->sum(fn ($s) => $s->maxScore()),
            'needs_grading' => $marks->count() < $stations->count(),
            'question_order' => [],
        ])->save();

        return $attempt;
    }
}
