<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use App\Services\Exams\QuestionImporter;
use App\Services\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Questions from Excel/CSV or Aiken into an exam, and student accounts from Excel. */
class ImportController extends Controller
{
    public function questions(Request $request, Exam $exam, SpreadsheetReader $reader, QuestionImporter $importer): RedirectResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:'.implode(',', QuestionImporter::EXTENSIONS)]],
            ['file.extensions' => 'الملف يجب أن يكون Excel (.xlsx) أو CSV أو نص Aiken/GIFT (.txt، .gift، أو Word .docx) أو Moodle XML (.xml).']);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $lang = $exam->languages[0] ?? 'ar';
        $result = $importer->fromFile($file->getRealPath(), $ext, $lang, $reader);

        $skipped = $result['skipped'];
        $good = [];
        foreach ($result['questions'] as $line => $q) {
            try {
                $good[] = ManageController::checkQuestions([$q], [$lang])[0];
            } catch (ValidationException $e) {
                $skipped[$line] = collect($e->errors())->flatten()->map(fn ($m) => preg_replace('/^السؤال 1: /u', '', $m))->implode(' ');
            }
        }
        if ($good === [] && $skipped !== []) {
            return back()->withErrors(['file' => $this->skippedText($skipped)]);
        }

        DB::transaction(function () use ($exam, $good) {
            $position = (int) $exam->questions()->max('position') + 1;
            foreach ($good as $q) {
                $question = Question::create([
                    'exam_id' => $exam->id, 'type' => $q['type'], 'body' => $q['body'], 'feedback' => $q['feedback'] ?: null,
                    'points' => $q['type'] === Question::DESCRIPTION ? 0 : $q['points'], 'settings' => $q['settings'] ?: null, 'position' => $position++,
                ]);
                foreach ($q['choices'] as $j => $c) {
                    Choice::create(['exam_question_id' => $question->id, 'body' => $c['body'], 'match' => $c['match'] ?: null, 'is_correct' => $c['is_correct'], 'position' => $j]);
                }
            }
        });
        ActivityLog::record('exam.imported', 'استورد '.count($good)." سؤالاً إلى \"{$exam->text('title')}\"", $exam);

        $message = 'أُضيف '.count($good).' سؤالاً.';
        if ($skipped) {
            $message .= ' تخطّيت '.count($skipped).': '.$this->skippedText($skipped);
        }

        return back()->with('success', $message);
    }

    public function questionsTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['النوع', 'السؤال', 'الخيار 1', 'الخيار 2', 'الخيار 3', 'الخيار 4', 'الإجابة', 'الدرجة', 'التغذية الراجعة']);
            fputcsv($out, ['اختيار', 'ما رمز الماء الكيميائي؟', 'H2O', 'CO2', 'O2', 'NaCl', 'أ', '2', '']);
            fputcsv($out, ['متعدد', 'أيّها غازات نبيلة؟', 'هيليوم', 'نيون', 'حديد', 'أرغون', 'أ، ب، د', '2', 'الحديد فلز']);
            fputcsv($out, ['صح وخطأ', 'يغلي الماء عند 100 درجة مئوية عند سطح البحر', '', '', '', '', 'صح', '1', '']);
            fputcsv($out, ['قصيرة', 'ما عاصمة العراق؟', '', '', '', '', 'بغداد|بغدادُ', '1', '']);
            fputcsv($out, ['رقمي', 'قيمة π لرقمين عشريين', '', '', '', '', '3.14±0.01', '1', '']);
            fputcsv($out, ['فراغ', 'عاصمة العراق [[بغداد]] وأكبر مدن الجنوب [[*البصرة|الموصل|أربيل]]', '', '', '', '', '', '2', '']);
            fputcsv($out, ['مقالي', 'اشرح عملية التأكسد', '', '', '', '', '', '5', '']);
            fclose($out);
        }, 'exam-questions-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Student accounts from Excel/CSV: الاسم | البريد | كلمة المرور (empty = generated).
     * The answer is a CSV with every new account and its password, shown once.
     */
    public function students(Request $request, SpreadsheetReader $reader): Response
    {
        abort_unless($request->user()->hasAdminCapability('can_manage_users'), 403);
        $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:xlsx,csv,txt'], 'group_id' => ['nullable', 'integer', 'exists:exam_groups,id']]);
        $group = $request->filled('group_id') ? \App\Models\Exams\Group::find($request->integer('group_id')) : null;
        $file = $request->file('file');
        $rows = $reader->read($file->getRealPath(), $file->getClientOriginalExtension());
        if (count($rows) < 2) {
            throw ValidationException::withMessages(['file' => 'الملف فارغ أو بلا صف عناوين.']);
        }
        $header = array_map(fn ($h) => \App\Services\Exams\Grader::normalize((string) $h), array_shift($rows));
        $col = fn (array $names) => collect($header)->search(fn ($h) => in_array($h, $names, true));
        $nameCol = $col(['الاسم', 'اسم الطالب', 'name']);
        $emailCol = $col(['البريد', 'البريد الالكتروني', 'الايميل', 'email']);
        $numberCol = $col(['الرقم الجامعي', 'الرقم', 'رقم الطالب', 'student number', 'student_number', 'id']);
        $passCol = $col(['كلمه المرور', 'password']);
        if ($nameCol === false || ($emailCol === false && $numberCol === false)) {
            throw ValidationException::withMessages(['file' => 'أحتاج عمود «الاسم» وعمود «البريد» أو «الرقم الجامعي» في الصف الأول.']);
        }

        $created = [];
        $skipped = [];
        DB::transaction(function () use ($rows, $nameCol, $emailCol, $numberCol, $passCol, $group, &$created, &$skipped) {
            foreach ($rows as $i => $row) {
                $name = trim((string) ($row[$nameCol] ?? ''));
                $email = $emailCol === false ? '' : mb_strtolower(trim((string) ($row[$emailCol] ?? '')));
                $number = $numberCol === false ? '' : trim((string) ($row[$numberCol] ?? ''));
                $who = $email !== '' ? $email : $number;
                if ($name === '' && $who === '') {
                    continue;
                }
                if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped[] = [$name, $who, 'بريد غير صالح'];

                    continue;
                }
                if ($email === '' && ($number === '' || preg_match('/[@\s]/', $number))) {
                    $skipped[] = [$name, $who, 'لا بريد ولا رقم صالح'];

                    continue;
                }
                $existing = ($email !== '' ? User::where('email', $email)->first() : null)
                    ?? ($number !== '' ? User::where('student_number', $number)->first() : null);
                if ($existing) {
                    $group?->members()->syncWithoutDetaching([$existing->id]);
                    $skipped[] = [$name, $who, $group ? 'موجود مسبقاً؛ أُضيف إلى المجموعة' : 'موجود مسبقاً'];

                    continue;
                }
                $password = $passCol !== false && trim((string) ($row[$passCol] ?? '')) !== '' ? trim((string) $row[$passCol]) : Str::password(10, symbols: false);
                $user = User::create([
                    'name' => Str::limit($name ?: $who, 255, ''),
                    // A student with only a number gets an address that can never receive mail.
                    'email' => $email !== '' ? $email : 'student-'.Str::slug($number).'-'.Str::lower(Str::random(4)).'@students.invalid',
                    'student_number' => $number !== '' ? $number : null,
                    'password' => Hash::make($password),
                    'role' => 'user', 'is_active' => true, 'email_verified_at' => now(),
                ]);
                $group?->members()->attach($user->id);
                $created[] = [$name, $who, $password];
            }
        });
        ActivityLog::record('exam.students_imported', 'استورد '.count($created).' طالباً من ملف');

        return response()->streamDownload(function () use ($created, $skipped) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['الاسم', 'البريد أو الرقم', 'كلمة المرور', 'الحالة']);
            foreach ($created as $r) {
                fputcsv($out, [...$r, 'أُنشئ']);
            }
            foreach ($skipped as [$n, $e, $why]) {
                fputcsv($out, [$n, $e, '', 'تُخطّي: '.$why]);
            }
            fclose($out);
        }, 'students-accounts-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function skippedText(array $skipped): string
    {
        return collect($skipped)->take(10)->map(fn ($why, $line) => "سطر $line: $why")->implode('؛ ').(count($skipped) > 10 ? '…' : '');
    }
}
