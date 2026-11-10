<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Answer;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use App\Services\Exams\Grader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paper bubble sheets (OMR) for choice questions: one sheet per student with a QR code that
 * says whose it is; after the exam the sheets are scanned (a scanner or a phone photo) and
 * read in the browser, checked by the teacher, and marked like an exam taken on a computer.
 */
class OmrController extends Controller
{
    /** The question types a bubble can answer. */
    public const TYPES = [Question::SINGLE, Question::MULTIPLE, Question::TRUE_FALSE];

    public const MAX_OPTIONS = 5;

    public function __construct(private Grader $grader) {}

    public function sheets(Request $request, Exam $exam): Response
    {
        abort_unless($exam->isManageableBy($request->user()), 404);

        return Inertia::render('Exams/Omr/Sheets', $this->layout($exam) + [
            'students' => $exam->roster()->values()->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'number' => $u->student_number,
                'code' => self::code($exam, $u->id)]),
            'site' => config('app.name'),
        ]);
    }

    public function scan(Request $request, Exam $exam): Response
    {
        abort_unless($exam->isManageableBy($request->user()), 404);

        return Inertia::render('Exams/Omr/Scan', $this->layout($exam) + [
            'students' => $exam->roster()->values()->mapWithKeys(fn (User $u) => [$u->id => $u->name]),
        ]);
    }

    /** One scanned sheet: the student's attempt is made from it, submitted and marked. */
    public function store(Request $request, Exam $exam): JsonResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:200'], 'answers' => ['present', 'array'], 'answers.*' => ['array'], 'answers.*.*' => ['integer', 'min:0', 'max:9']]);
        if (! preg_match('/^EXOMR:(\d+):(\d+):([A-Z0-9]{10})$/', $data['code'], $m) || (int) $m[1] !== $exam->id || ! hash_equals(self::code($exam, (int) $m[2]), $data['code'])) {
            return response()->json(['message' => 'رمز الورقة لا يعود لهذا الامتحان.'], 422);
        }
        $user = User::findOrFail((int) $m[2]);
        $questions = $this->bubbleQuestions($exam)->keyBy('id');

        $attempt = DB::transaction(function () use ($exam, $user, $questions, $data) {
            // A sheet read again replaces its earlier reading.
            $attempt = Attempt::firstOrNew(['exam_id' => $exam->id, 'user_id' => $user->id, 'number' => 1]);
            $attempt->fill(['started_at' => $attempt->started_at ?? now(), 'submitted_at' => now(), 'question_order' => $questions->keys()->all(), 'ip' => 'omr'])->save();
            foreach ($questions as $qid => $q) {
                $picked = collect($data['answers'][$qid] ?? [])->unique()->filter(fn ($i) => isset($q->choices[$i]));
                $ids = $picked->map(fn ($i) => $q->choices[$i]->id)->values();
                $response = $ids->isEmpty() ? null : ($q->type === Question::MULTIPLE ? ['choices' => $ids->all()] : ['choice' => $ids->first()]);
                Answer::updateOrCreate(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $qid], ['response' => $response]);
                \App\Models\Exams\AnswerLog::record($attempt, $qid, $response, 'omr', null);
            }
            $this->grader->grade($attempt);

            return $attempt->fresh();
        });
        ActivityLog::record('exam.omr', "صحّح ورقة ببل شيت لـ {$user->name} في \"{$exam->text('title')}\"", $exam);

        return response()->json(['ok' => true, 'name' => $user->name, 'score' => $attempt->score, 'max' => $attempt->max_score]);
    }

    /** The exam as an Auto Multiple Choice project: the AMC-TXT source, the students list and how to use them. */
    public function amcExport(Request $request, Exam $exam, \App\Services\Exams\AutoMultipleChoice $amc): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        $zipPath = tempnam(sys_get_temp_dir(), 'amc');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::OVERWRITE);
        $zip->addFromString('exam.txt', $amc->source($exam));
        $zip->addFromString('students.csv', $amc->students($exam));
        foreach ($amc->pictures($exam) as $p) {
            $zip->addFromString('img/'.$p['file'], $p['bytes']);
        }
        $zip->addFromString('README-ar.txt', implode("\n", [
            'مشروع Auto Multiple Choice لامتحان: '.$exam->text('title'),
            '',
            '1. ثبّت Auto Multiple Choice (لينكس: sudo apt install auto-multiple-choice) ومعه خط عربي (ttf-arabeyes) إن كان الامتحان بالعربية.',
            '2. أنشئ مشروعاً جديداً، واختر «ملف موجود» وحدّد exam.txt من هذا الملف المضغوط، وضع students.csv في مجلد المشروع نفسه.',
            '3. «تحضير» ثم اطبع الأوراق: تخرج ورقة باسم كل طالب (اسم الملف students.csv، المفتاح id).',
            '4. بعد الامتحان: امسح الأوراق، ثم «قراءة الأوراق» ثم «التصحيح»، و«ربط الطلاب» بالقائمة students.csv بالمفتاح id.',
            '5. «التقارير» ← تصدير CSV، وفعّل «الخانات المؤشّرة» (ticked) بصيغة الحروف AB.',
            '6. ارفع ملف CSV في صفحة الببل شيت في النظام: تُصحَّح الإجابات وتظهر في النتائج.',
            '',
            'أسئلة الاختيار من متعدد وصح/خطأ فقط تدخل في المشروع؛ الأنواع الأخرى تُصحَّح في النظام يدوياً.',
        ]));
        $zip->close();

        return response()->download($zipPath, 'amc-exam-'.$exam->id.'.zip')->deleteFileAfterSend();
    }

    /** AMC's results CSV: each row a student's marked attempt. */
    public function amcImport(Request $request, Exam $exam, \App\Services\Exams\AutoMultipleChoice $amc): \Illuminate\Http\RedirectResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        $request->validate(['file' => ['required', 'file', 'max:20480', 'extensions:csv,txt']]);
        try {
            $r = $amc->import($exam, (string) file_get_contents($request->file('file')->getRealPath()));
        } catch (\InvalidArgumentException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        ActivityLog::record('exam.amc', "أدخل نتائج {$r['attempts']} طالباً من Auto Multiple Choice في \"{$exam->text('title')}\"", $exam);
        $how = ['ticked' => 'من الخانات المؤشّرة، وصُحّحت هنا', 'scores' => 'من درجات AMC لكل سؤال', 'mark' => 'من الدرجة الكلية في AMC'][$r['how']];

        return back()->with('success', "أُدخلت نتائج {$r['attempts']} طالباً ({$how}).".($r['unknown'] ? ' مفاتيح غير معروفة: '.implode('، ', $r['unknown']) : ''));
    }

    /** The code in a sheet's QR: the exam, the student and a check only this server can make. */
    public static function code(Exam $exam, int $userId): string
    {
        return 'EXOMR:'.$exam->id.':'.$userId.':'.strtoupper(substr(hash_hmac('sha256', 'omr|'.$exam->id.'|'.$userId, (string) config('app.key')), 0, 10));
    }

    /** The questions on the sheet, in the exam's written order, with their options as printed. */
    private function bubbleQuestions(Exam $exam)
    {
        return $exam->questions()->with('choices')->get()->filter(fn (Question $q) => in_array($q->type, self::TYPES, true))
            ->each(fn (Question $q) => $q->setRelation('choices', $q->choices->values()->take(self::MAX_OPTIONS)))->values();
    }

    private function layout(Exam $exam): array
    {
        $qs = $this->bubbleQuestions($exam);

        return [
            'exam' => ['id' => $exam->id, 'title' => $exam->text('title'), 'subject' => $exam->subject],
            'questions' => $qs->map(fn (Question $q) => ['id' => $q->id, 'type' => $q->type, 'options' => $q->choices->count()])->values(),
            'skipped' => $exam->questions()->whereNotIn('type', [...self::TYPES, Question::DESCRIPTION])->count(),
        ];
    }
}
