<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Exams\Exam;
use App\Services\Exams\QtiPackage;
use App\Services\Exams\Transfer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Exams and results in and out of the system: this system's own files (to move between servers) and QTI 3.0. */
class TransferController extends Controller
{
    public function __construct(private Transfer $transfer) {}

    public function exportExam(Request $request, Exam $exam): StreamedResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);

        return $this->json($this->transfer->exportExam($exam), 'exam-'.$exam->id.'-'.Str::slug($exam->text('title')) ?: 'exam');
    }

    public function importExam(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:512000']]);
        $file = $request->file('file');
        try {
            $exam = strtolower($file->getClientOriginalExtension()) === 'zip'
                ? app(QtiPackage::class)->import($file->getRealPath(), $request->user())
                : $this->transfer->importExam($this->read($file->getRealPath()), $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        ActivityLog::record('exam.imported_package', "استورد الامتحان \"{$exam->text('title')}\" من ملف", $exam);

        return redirect()->route('exams.manage.edit', $exam)->with('success', 'استُورد الامتحان (غير منشور). راجعه ثم انشره.');
    }

    public function exportResults(Request $request, Exam $exam): StreamedResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);

        return $this->json($this->transfer->exportResults($exam), 'results-'.$exam->id);
    }

    public function importResults(Request $request, Exam $exam): RedirectResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        $request->validate(['file' => ['required', 'file', 'max:512000']]);
        try {
            $done = $this->transfer->importResults($exam, $this->read($request->file('file')->getRealPath()));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        ActivityLog::record('exam.imported_results', "أدخل {$done['attempts']} محاولة من سيرفر آخر في \"{$exam->text('title')}\"", $exam);

        return back()->with('success', "أُدخلت {$done['attempts']} محاولة، وأُنشئ {$done['accounts']} حساب طالب جديد.");
    }

    public function exportQti(Request $request, Exam $exam): BinaryFileResponse
    {
        abort_unless($exam->isManageableBy($request->user()), 404);
        $path = app(QtiPackage::class)->export($exam);

        return response()->download($path, 'exam-'.$exam->id.'-qti3.zip')->deleteFileAfterSend();
    }

    private function read(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            throw new InvalidArgumentException('الملف ليس ملف امتحان أو نتائج صالحاً.');
        }

        return $data;
    }

    private function json(array $data, string $name): StreamedResponse
    {
        return response()->streamDownload(fn () => print (json_encode($data, JSON_UNESCAPED_UNICODE)), $name.'.examnest.json', ['Content-Type' => 'application/json']);
    }
}
