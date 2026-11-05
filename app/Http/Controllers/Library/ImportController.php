<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\LibrarySection;
use App\Services\Library\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk add books from Excel (.xlsx) or CSV. The first row holds the column names, in
 * Arabic or English; unknown columns are ignored.
 */
class ImportController extends Controller
{
    public const COLUMNS = [
        'title' => ['title', 'العنوان', 'اسم الكتاب', 'الكتاب'],
        'authors' => ['authors', 'author', 'المؤلف', 'المؤلفون'],
        'isbn' => ['isbn', 'ردمك'],
        'publisher' => ['publisher', 'الناشر'],
        'year' => ['year', 'السنة', 'سنة النشر'],
        'language' => ['language', 'اللغة'],
        'edition' => ['edition', 'الطبعة'],
        'section' => ['section', 'القسم'],
        'copies' => ['copies', 'النسخ', 'عدد النسخ'],
        'shelf' => ['shelf', 'الرف', 'الموقع'],
        'barcode' => ['barcode', 'الباركود', 'باركود'],
    ];

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            echo "\xEF\xBB\xBF";
            echo "العنوان,المؤلف,ISBN,الناشر,السنة,اللغة,القسم,عدد النسخ,الرف\n";
            echo "Oral and Maxillofacial Surgery,Peterson,9780323414920,Elsevier,2018,English,الدراسات الأولية / جراحة الفم,3,A3\n";
        }, 'library-books-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(Request $request, SpreadsheetReader $reader): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:xlsx,csv,txt']], [
            'file.mimes' => 'الملف يجب أن يكون Excel (.xlsx) أو CSV.',
        ]);

        $file = $request->file('file');
        $rows = $reader->read($file->getRealPath(), $file->getClientOriginalExtension());
        if (count($rows) < 2) {
            return back()->withErrors(['file' => 'الملف فارغ أو لا يحتوي صف عناوين للأعمدة.']);
        }

        $map = $this->mapHeader(array_shift($rows));
        if (! isset($map['title'])) {
            return back()->withErrors(['file' => 'لم أجد عمود «العنوان» (title) في الصف الأول.']);
        }

        $added = 0;
        $copies = 0;
        $skipped = [];
        DB::transaction(function () use ($rows, $map, $request, &$added, &$copies, &$skipped) {
            foreach ($rows as $i => $row) {
                $get = fn ($key) => isset($map[$key]) ? trim((string) ($row[$map[$key]] ?? '')) : '';
                $title = $get('title');
                if ($title === '') {
                    continue;
                }

                $barcode = $get('barcode');
                if ($barcode !== '' && BookCopy::where('barcode', $barcode)->exists()) {
                    $skipped[] = $i + 2;

                    continue;
                }

                $section = $get('section') !== '' ? LibrarySection::fromPath($get('section')) : null;
                $book = Book::create([
                    'title' => mb_substr($title, 0, 255),
                    'authors' => $get('authors') ?: null,
                    'isbn' => preg_replace('/[^0-9Xx]/', '', $get('isbn')) ?: null,
                    'publisher' => $get('publisher') ?: null,
                    'year' => mb_substr($get('year'), 0, 10) ?: null,
                    'language' => mb_substr($get('language'), 0, 20) ?: null,
                    'edition' => mb_substr($get('edition'), 0, 50) ?: null,
                    'section_id' => $section?->id,
                    'created_by' => $request->user()->id,
                ]);
                $added++;

                $n = $get('copies') === '' ? 1 : max(0, min(100, (int) $get('copies')));
                for ($c = 0; $c < $n; $c++) {
                    $book->copies()->create([
                        'barcode' => ($c === 0 && $barcode !== '') ? $barcode : BookCopy::newBarcodePlaceholder(),
                        'shelf' => $get('shelf') ?: null,
                    ]);
                    $copies++;
                }
            }
        });

        ActivityLog::record('library.import', "استورد {$added} كتاباً ({$copies} نسخة) إلى المكتبة");

        $msg = "تم استيراد {$added} كتاباً و{$copies} نسخة.";
        if ($skipped) {
            $msg .= ' تم تخطي الصفوف ذات الباركود المكرر: '.implode('، ', array_slice($skipped, 0, 20));
        }

        return back()->with('success', $msg);
    }

    /** @return array<string, int> column key => index */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $index => $name) {
            $name = mb_strtolower(trim((string) $name));
            foreach (self::COLUMNS as $key => $aliases) {
                if (! isset($map[$key]) && in_array($name, array_map('mb_strtolower', $aliases), true)) {
                    $map[$key] = $index;
                }
            }
        }

        return $map;
    }
}
