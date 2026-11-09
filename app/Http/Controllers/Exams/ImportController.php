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
        $request->validate(['file' => ['required', 'file', 'max:204800', 'extensions:'.implode(',', QuestionImporter::EXTENSIONS)]],
            ['file.extensions' => 'الملف يجب أن يكون Excel (.xlsx) أو CSV أو نص Aiken/GIFT (.txt، .gift، أو Word .docx) أو Moodle XML (.xml)، أو ZIP فيه ملف Excel مع صوره.']);

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
                    'points' => $q['type'] === Question::DESCRIPTION ? 0 : $q['points'], 'settings' => $q['settings'] ?: null, 'position' => $position++, 'image' => $q['image'] ?? null,
                ]);
                foreach ($q['choices'] as $j => $c) {
                    Choice::create(['exam_question_id' => $question->id, 'body' => $c['body'], 'match' => $c['match'] ?: null, 'is_correct' => $c['is_correct'], 'position' => $j, 'image' => $c['image'] ?? null]);
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
            // «الصورة» and «صورة الخيار N»: a picture's file name; send the sheet and the pictures in one ZIP.
            fputcsv($out, ['النوع', 'السؤال', 'الخيار 1', 'الخيار 2', 'الخيار 3', 'الخيار 4', 'الإجابة', 'الدرجة', 'التغذية الراجعة', 'الصورة', 'صورة الخيار 1', 'صورة الخيار 2', 'صورة الخيار 3', 'صورة الخيار 4']);
            fputcsv($out, ['اختيار', 'ما رمز الماء الكيميائي؟', 'H2O', 'CO2', 'O2', 'NaCl', 'أ', '2', '', '', '', '', '', '']);
            fputcsv($out, ['متعدد', 'أيّها غازات نبيلة؟', 'هيليوم', 'نيون', 'حديد', 'أرغون', 'أ، ب، د', '2', 'الحديد فلز', '', '', '', '', '']);
            fputcsv($out, ['اختيار', 'أيّ هذه الصور لخلية نباتية؟', '', '', '', '', '2', '1', '', 'cells.png', 'a.png', 'b.png', 'c.png', '']);
            fputcsv($out, ['صح وخطأ', 'يغلي الماء عند 100 درجة مئوية عند سطح البحر', '', '', '', '', 'صح', '1', '', '', '', '', '', '']);
            fputcsv($out, ['قصيرة', 'ما عاصمة العراق؟', '', '', '', '', 'بغداد|بغدادُ', '1', '', '', '', '', '', '']);
            fputcsv($out, ['رقمي', 'قيمة π لرقمين عشريين', '', '', '', '', '3.14±0.01', '1', '', '', '', '', '', '']);
            fputcsv($out, ['فراغ', 'عاصمة العراق [[بغداد]] وأكبر مدن الجنوب [[*البصرة|الموصل|أربيل]]', '', '', '', '', '', '2', '', '', '', '', '', '']);
            fputcsv($out, ['مقالي', 'اشرح عملية التأكسد', '', '', '', '', '', '5', '', '', '', '', '', '']);
            fclose($out);
        }, 'exam-questions-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The exam's questions as a ZIP: «questions.csv» (the same columns the import reads, pictures by
     * file name) and the pictures, so it can be edited in Excel and imported here or into another exam.
     * Kinds the sheet cannot hold (matching, hotspot…) are listed in «not-included.txt».
     */
    public function questionsExport(Request $request, Exam $exam): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        $lang = $exam->languages[0] ?? 'ar';
        $words = ['single' => 'اختيار', 'multiple' => 'متعدد', 'true_false' => 'صح وخطأ', 'short' => 'قصيرة', 'numeric' => 'رقمي', 'cloze' => 'فراغ', 'essay' => 'مقالي', 'description' => 'نص توضيحي'];
        $questions = $exam->questions()->with('choices')->get();
        $most = max(4, (int) $questions->map(fn ($q) => $q->choices->count())->max());
        $path = tempnam(sys_get_temp_dir(), 'qx').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $pictures = [];
        $picture = function (?string $name) use (&$pictures, $zip) {
            if (! $name || ! ManageController::picture($name)) {
                return '';
            }
            if (! isset($pictures[$name])) {
                $file = 'pictures/'.(count($pictures) + 1).'.'.pathinfo($name, PATHINFO_EXTENSION);
                $zip->addFromString($file, \Illuminate\Support\Facades\Storage::disk('local')->get(ManageController::IMAGES.'/'.$name));
                $pictures[$name] = basename($file);
            }

            return $pictures[$name];
        };
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        $choiceCols = array_map(fn ($k) => 'الخيار '.$k, range(1, $most));
        $pictureCols = array_map(fn ($k) => 'صورة الخيار '.$k, range(1, $most));
        fputcsv($out, ['النوع', 'السؤال', ...$choiceCols, 'الإجابة', 'الدرجة', 'التغذية الراجعة', 'الصورة', ...$pictureCols]);
        $left = [];
        foreach ($questions as $n => $q) {
            if (! isset($words[$q->type])) {
                $left[] = ($n + 1).'. '.$q->type.': '.mb_substr(\App\Services\Exams\HtmlCleaner::text(Question::pick($q->body, $lang, $exam->languages ?? [])), 0, 80);

                continue;
            }
            $text = fn ($v) => \App\Services\Exams\HtmlCleaner::text(Question::pick($v, $lang, $exam->languages ?? []));
            $choices = $q->choices->values();
            $answer = match ($q->type) {
                'single', 'multiple', 'true_false' => $choices->keys()->filter(fn ($k) => $choices[$k]->is_correct)->map(fn ($k) => $k + 1)->implode('، '),
                'short' => implode('|', (array) ($q->settings['answers'] ?? [])),
                'numeric' => ($q->settings['answer'] ?? '').(filled($q->settings['tolerance'] ?? null) && ($q->settings['tolerance'] ?? '0') !== '0' ? '±'.$q->settings['tolerance'] : ''),
                default => '',
            };
            fputcsv($out, [
                $words[$q->type], $text($q->body),
                ...array_map(fn ($k) => isset($choices[$k]) ? $text($choices[$k]->body) : '', range(0, $most - 1)),
                $answer, $q->type === 'description' ? '' : (string) (float) $q->points, $text($q->feedback), $picture($q->image),
                ...array_map(fn ($k) => isset($choices[$k]) ? $picture($choices[$k]->image) : '', range(0, $most - 1)),
            ]);
        }
        rewind($out);
        $zip->addFromString('questions.csv', stream_get_contents($out));
        fclose($out);
        if ($left) {
            $zip->addFromString('not-included.txt', "أسئلة لا يحملها جدول Excel (انقلها بملف الامتحان .examnest.json):\n".implode("\n", $left)."\n");
        }
        $zip->close();

        return response()->download($path, 'exam-'.$exam->id.'-questions.zip')->deleteFileAfterSend();
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
        $phoneCol = $col(['الهاتف', 'رقم الهاتف', 'الموبايل', 'phone', 'mobile']);
        if ($nameCol === false || ($emailCol === false && $numberCol === false)) {
            throw ValidationException::withMessages(['file' => 'أحتاج عمود «الاسم» وعمود «البريد» أو «الرقم الجامعي» في الصف الأول.']);
        }

        $created = [];
        $skipped = [];
        DB::transaction(function () use ($rows, $nameCol, $emailCol, $numberCol, $passCol, $phoneCol, $group, &$created, &$skipped) {
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
                    'phone' => $phoneCol !== false && trim((string) ($row[$phoneCol] ?? '')) !== '' ? mb_substr(trim((string) $row[$phoneCol]), 0, 30) : null,
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

    /** One student's official photo (or none). */
    public function photo(Request $request, User $user): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->hasAdminCapability('can_manage_users'), 403);
        if ($request->boolean('remove')) {
            \App\Services\Exams\StudentPhoto::remove($user);

            return response()->json(['photo' => null]);
        }
        $request->validate(['photo' => ['required', 'file', 'max:15360', 'mimes:jpg,jpeg,png,webp']]);
        abort_unless(\App\Services\Exams\StudentPhoto::store($user, (string) file_get_contents($request->file('photo')->getRealPath())), 422, 'الملف ليس صورة.');

        return response()->json(['photo' => $user->fresh()->photoUrl()]);
    }

    /**
     * Photos for many students at once: a ZIP whose pictures are named by student number or
     * e-mail (2024001.jpg, ali@uni.edu.iq.png). Says which matched nobody.
     */
    public function photos(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()->hasAdminCapability('can_manage_users'), 403);
        $request->validate(['file' => ['required', 'file', 'max:512000', 'extensions:zip']]);
        $zip = new \ZipArchive;
        if ($zip->open($request->file('file')->getRealPath()) !== true) {
            throw ValidationException::withMessages(['file' => 'تعذّر فتح ملف ZIP.']);
        }
        $saved = 0;
        $unknown = [];
        for ($i = 0; $i < min($zip->numFiles, 5000); $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/') || str_starts_with(basename($name), '.') || str_contains($name, '__MACOSX')) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                continue;
            }
            $key = pathinfo($name, PATHINFO_FILENAME);
            $user = str_contains($key, '@') ? User::where('email', mb_strtolower($key))->first() : User::where('student_number', $key)->first();
            $stat = $zip->statIndex($i);
            if (! $user || ($stat['size'] ?? 0) > 15 * 1024 * 1024) {
                $unknown[] = basename($name);

                continue;
            }
            if (\App\Services\Exams\StudentPhoto::store($user, (string) $zip->getFromIndex($i))) {
                $saved++;
            } else {
                $unknown[] = basename($name);
            }
        }
        $zip->close();
        ActivityLog::record('exam.photos_imported', "رفع صور {$saved} طالباً");

        return response()->json(['saved' => $saved, 'unknown' => array_slice($unknown, 0, 50), 'unknown_count' => count($unknown)]);
    }

    private function skippedText(array $skipped): string
    {
        return collect($skipped)->take(10)->map(fn ($why, $line) => "سطر $line: $why")->implode('؛ ').(count($skipped) > 10 ? '…' : '');
    }
}
