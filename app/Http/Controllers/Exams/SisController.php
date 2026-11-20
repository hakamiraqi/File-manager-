<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\Setting;
use App\Models\User;
use App\Services\Exams\Sis\SisLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * «ربط نظام الطلبة»: connect to SIS (admins), make groups from its course sections, and send an
 * exam's marks to a component there, where the course teacher accepts them into the gradebook.
 */
class SisController extends Controller
{
    // ---- the link (admins) ----

    public function show(Request $request): Response
    {
        abort_unless($request->user()->isAdmin(), 403);

        return Inertia::render('Exams/Monitor/Sis', [
            'sis' => SisLink::forPage() + [
                'url' => SisLink::url() ?: null, 'university' => Setting::get('sis_university') ?: null,
                'last_ok' => Setting::get('sis_last_ok') ?: null, 'error' => Setting::get('sis_error') ?: null,
            ],
            'groups' => Group::whereNotNull('sis_offering_id')->withCount('members')->orderBy('name')->get(['id', 'name', 'sis_offering_id'])
                ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'members' => $g->members_count]),
            'pushes' => DB::table('exam_sis_pushes')->join('exams', 'exams.id', '=', 'exam_sis_pushes.exam_id')->latest('exam_sis_pushes.id')->limit(10)
                ->get(['exam_sis_pushes.*', 'exams.title as exam_title'])
                ->map(fn ($p) => ['id' => $p->id, 'exam_id' => $p->exam_id, 'exam' => $this->title($p->exam_title), 'offering' => $p->offering_label, 'component' => $p->component,
                    'sent' => $p->sent, 'status' => $p->status, 'at' => $p->created_at]),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['url' => ['required', 'url:http,https', 'max:255'], 'key' => ['required', 'string', 'min:20', 'max:255']]);
        try {
            SisLink::connect($data['url'], trim($data['key']));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
        ActivityLog::record('exam.sis_connected', 'ربط نظام الامتحانات بنظام الطلبة');

        return back()->with('success', 'رُبط نظام الطلبة.');
    }

    public function refresh(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        try {
            (new SisLink)->refresh();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'الاتصال بنظام الطلبة يعمل.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        SisLink::disconnect();
        ActivityLog::record('exam.sis_disconnected', 'ألغى ربط نظام الطلبة');

        return back()->with('success', 'أُلغي ربط نظام الطلبة.');
    }

    // ---- course sections → groups (teachers) ----

    public function offerings(): JsonResponse
    {
        try {
            $rows = (new SisLink)->offerings();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $made = Group::whereNotNull('sis_offering_id')->pluck('id', 'sis_offering_id');

        return response()->json(['data' => collect($rows)->map(fn ($o) => $o + ['group_id' => $made[$o['id']] ?? null])->values()]);
    }

    /** One SIS course section as a group: its students now (round 1: all; later rounds: the resits). */
    public function importGroup(Request $request): RedirectResponse
    {
        $id = $request->validate(['offering_id' => ['required', 'integer', 'min:1']])['offering_id'];
        try {
            $sis = new SisLink;
            $offering = collect($sis->offerings())->firstWhere('id', $id) ?? throw new RuntimeException('الشعبة غير موجودة في نظام الطلبة.');
            $students = $sis->students($id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $round = (int) ($offering['current_round'] ?? 1);
        $name = mb_substr(trim($offering['course']['code'].' — '.$offering['course']['name_ar'].' / '.$offering['section']
            .($offering['shift'] === 'evening' ? ' مسائي' : '').($round > 1 ? ' (الدور '.$round.')' : '')), 0, 255);
        $created = 0;
        $group = DB::transaction(function () use ($request, $offering, $students, $name, &$created) {
            $group = Group::firstOrCreate(['sis_offering_id' => $offering['id'], 'name' => $name], [
                'user_id' => $request->user()->id, 'stage' => $offering['stage'] ? (string) $offering['stage'] : null, 'department' => $offering['department'] ?? null,
            ]);
            $ids = [];
            foreach ($students as $s) {
                $number = trim((string) ($s['university_number'] ?? ''));
                if ($number === '') {
                    continue;
                }
                $user = User::where('student_number', $number)->first();
                if (! $user) {
                    $user = User::create([
                        'name' => Str::limit((string) ($s['name_ar'] ?? $number), 255, ''),
                        'email' => 'student-'.Str::slug($number).'-'.Str::lower(Str::random(4)).'@students.invalid',
                        'student_number' => $number, 'password' => Hash::make(Str::password(16)),
                        'role' => 'user', 'is_active' => true, 'email_verified_at' => now(),
                    ]);
                    $created++;
                }
                $ids[] = $user->id;
            }
            // the group follows SIS: a student who withdrew there leaves it here
            $group->members()->sync($ids);

            return $group;
        });
        ActivityLog::record('exam.sis_group', "أخذ من نظام الطلبة المجموعة \"{$group->name}\" (".count($students).' طالباً)');

        return redirect()->route('exams.manage.groups', ['group' => $group->id])
            ->with('success', 'أُخذت الشعبة من نظام الطلبة: '.count($students).' طالباً'.($created ? "، منهم {$created} حساباً جديداً (اطبع لهم بطاقات دخول)" : '').'.');
    }

    // ---- marks → SIS (the exam's managers) ----

    public function pushMarks(Request $request, Exam $exam): RedirectResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 403);
        $data = $request->validate([
            'offering_id' => ['required', 'integer', 'min:1'],
            'component' => ['required', 'string', 'max:40'],
            'absent' => ['boolean'],
        ]);
        if ($exam->attempts()->where('needs_grading', true)->exists()) {
            return back()->with('error', 'بعض الإجابات لم تُصحّح بعد. أكمل التصحيح ثم أرسل الدرجات.');
        }
        $max = $exam->maxScore();
        if ($max <= 0) {
            return back()->with('error', 'الدرجة الكلية للامتحان صفر.');
        }

        try {
            $sis = new SisLink;
            $offering = collect($sis->offerings())->firstWhere('id', $data['offering_id']) ?? throw new RuntimeException('الشعبة غير موجودة في نظام الطلبة.');
            $component = collect($offering['components'])->firstWhere('code', $data['component']);
            if (! $component || ! ($component['accepts_marks'] ?? false)) {
                throw new RuntimeException('هذا المكوّن لا يستقبل درجات الآن في نظام الطلبة (الدور منتهٍ أو النتائج مرفوعة).');
            }

            $marks = [];
            $noNumber = 0;
            foreach ($exam->roster() as $u) {
                if (! $u->student_number) {
                    $noNumber++;

                    continue;
                }
                $score = $exam->finalScoreFor($u);
                if ($score === null) {
                    if ($request->boolean('absent')) {
                        $marks[] = ['university_number' => (string) $u->student_number, 'absent' => true];
                    }

                    continue;
                }
                $marks[] = ['university_number' => (string) $u->student_number, 'mark' => round($score / $max * (float) $component['max_mark'], 2)];
            }
            if (! $marks) {
                throw new RuntimeException('لا توجد درجات لطلاب لهم رقم جامعي.');
            }
            $reference = 'ex'.$exam->id.'-o'.$offering['id'].'-'.Str::slug($component['code']).'-'.substr(sha1(json_encode($marks)), 0, 12);
            $res = $sis->sendMarks($offering['id'], $component['code'], $reference, $marks);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        DB::table('exam_sis_pushes')->updateOrInsert(['reference' => $reference], [
            'exam_id' => $exam->id, 'offering_id' => $offering['id'],
            'offering_label' => mb_substr($offering['course']['code'].' — '.$offering['course']['name_ar'].' / '.$offering['section'], 0, 255),
            'component' => $component['code'], 'scale_to' => $component['max_mark'], 'batch_id' => $res['batch_id'] ?? null,
            'status' => $res['status'] ?? 'pending', 'sent' => $res['accepted'] ?? count($marks), 'unknown' => json_encode($res['unknown'] ?? []),
            'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        ActivityLog::record('exam.sis_marks', "أرسل درجات \"{$exam->text('title')}\" إلى نظام الطلبة (".count($marks).')', $exam);
        $unknown = count($res['unknown'] ?? []);

        return back()->with('success', 'أُرسلت '.($res['accepted'] ?? count($marks)).' درجة إلى نظام الطلبة من '.(float) $component['max_mark']
            .'. تدخل السجل بعد اعتماد تدريسي المادة.'.($unknown ? " {$unknown} رقماً غير موجود في الشعبة هناك." : '').($noNumber ? " {$noNumber} طالباً بلا رقم جامعي لم يُرسلوا." : ''));
    }

    /** What the teachers in SIS did with what was sent. */
    public function pushStatus(Request $request, Exam $exam): RedirectResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 403);
        $sis = new SisLink;
        try {
            foreach (DB::table('exam_sis_pushes')->where('exam_id', $exam->id)->where('status', 'pending')->whereNotNull('batch_id')->get() as $p) {
                $b = $sis->batch((int) $p->batch_id);
                DB::table('exam_sis_pushes')->where('id', $p->id)->update(['status' => $b['status'] ?? $p->status, 'updated_at' => now()]);
            }
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'حُدّثت حالة الإرسال.');
    }

    /** The sending panel on an exam's results page. */
    public static function forResults(Exam $exam): array
    {
        return SisLink::forPage() + [
            'group_offerings' => $exam->groups()->whereNotNull('sis_offering_id')->pluck('sis_offering_id')->unique()->values(),
            'pushes' => DB::table('exam_sis_pushes')->where('exam_id', $exam->id)->latest('id')->limit(5)->get()
                ->map(fn ($p) => ['id' => $p->id, 'offering' => $p->offering_label, 'component' => $p->component, 'scale' => (float) $p->scale_to,
                    'sent' => $p->sent, 'status' => $p->status, 'at' => $p->created_at]),
        ];
    }

    private function title(?string $raw): string
    {
        $v = json_decode((string) $raw, true);

        return is_array($v) ? (string) (reset($v) ?: '') : (string) $raw;
    }
}
