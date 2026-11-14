<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Library\Book;
use App\Models\Library\BookCopy;
use App\Models\Library\LibrarySection;
use App\Services\Library\Marc\MarcMapper;
use App\Services\Library\Marc\MarcReader;
use App\Services\Library\Marc\MarcRecord;
use App\Services\Library\Marc\MarcWriter;
use App\Services\Library\SpreadsheetReader;
use Illuminate\Http\JsonResponse;
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

    /**
     * MARC records from Koha or any library system (2026-11-14): ISO 2709 (.mrc) or MARCXML,
     * MARC21 or UNIMARC; Koha's items (952 / 995) become copies. A book already here (same
     * control number or ISBN) is skipped or updated, and only copies with new barcodes are added.
     */
    public function marc(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:102400'],
            'existing' => ['nullable', 'in:skip,update'],
            'section_id' => ['nullable', 'integer', 'exists:library_sections,id'],
        ]);
        $path = $request->file('file')->getRealPath();
        $update = ($data['existing'] ?? 'skip') === 'update';
        $added = $updated = $skipped = $copies = $bad = 0;

        try {
            $batch = [];
            foreach (MarcReader::file($path) as $record) {
                $batch[] = $record;
                if (count($batch) === 200) {
                    $this->importBatch($batch, $request, $data, $update, $added, $updated, $skipped, $copies, $bad);
                    $batch = [];
                }
            }
            $this->importBatch($batch, $request, $data, $update, $added, $updated, $skipped, $copies, $bad);
            if ($added + $updated + $skipped + $bad === 0) {
                throw new \RuntimeException('No MARC records in the file.');
            }
        } catch (\Throwable $e) {
            report($e);
            $msg = 'تعذّرت قراءة ملف MARC. تأكد أنه بصيغة ISO 2709 (.mrc) أو MARCXML.';
            if ($added + $updated) {
                $msg .= " (أُضيف قبل التوقف {$added} وحُدّث {$updated}.)";
            }

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => $msg], 422)
                : back()->withErrors(['file' => $msg]);
        }

        ActivityLog::record('library.import', "استورد من MARC {$added} كتاباً جديداً و{$copies} نسخة، وحدّث {$updated}");
        $msg = "MARC: أُضيف {$added} كتاباً و{$copies} نسخة".($updated ? "، وحُدّث {$updated}" : '').($skipped ? "، وتُخطّي {$skipped} موجود مسبقاً" : '').($bad ? "، و{$bad} سجلاً بلا عنوان" : '').'.';

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['message' => $msg, 'added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'copies' => $copies])
            : back()->with('success', $msg);
    }

    /** @param list<MarcRecord> $records */
    private function importBatch(array $records, Request $request, array $data, bool $update, int &$added, int &$updated, int &$skipped, int &$copies, int &$bad): void
    {
        if (! $records) {
            return;
        }
        $fit = fn (?string $v, int $n) => $v === null || $v === '' ? null : mb_substr($v, 0, $n);
        DB::transaction(function () use ($records, $request, $data, $update, $fit, &$added, &$updated, &$skipped, &$copies, &$bad) {
            foreach ($records as $record) {
                ['book' => $b, 'copies' => $items] = MarcMapper::toBook($record);
                if (! $b['title']) {
                    $bad++;

                    continue;
                }
                $fields = [
                    'title' => $fit($b['title'], 255), 'subtitle' => $fit($b['subtitle'], 255), 'authors' => $fit($b['authors'], 255),
                    'isbn' => $fit($b['isbn'], 20), 'publisher' => $fit($b['publisher'], 255), 'year' => $fit($b['year'], 10),
                    'language' => $fit($b['language'], 20), 'edition' => $fit($b['edition'], 50), 'pages' => $b['pages'] ?: null,
                    'description' => $fit($b['description'], 5000), 'subjects' => $fit($b['subjects'], 2000),
                    'call_number' => $fit($b['call_number'], 64), 'marc_control' => $fit($b['marc_control'], 64),
                    'marc_xml' => MarcWriter::xml($record, true),
                ];
                $book = null;
                if ($fields['marc_control']) {
                    $book = Book::where('marc_control', $fields['marc_control'])->first();
                }
                if (! $book && $fields['isbn']) {
                    $book = Book::where('isbn', $fields['isbn'])->first();
                }
                if ($book) {
                    if ($update) {
                        $book->update(array_filter($fields, fn ($v) => $v !== null));
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    $book = Book::create([...$fields, 'section_id' => $data['section_id'] ?? null, 'created_by' => $request->user()->id]);
                    $added++;
                }
                foreach ($items as $item) {
                    $barcode = $fit(trim((string) $item['barcode']), 64);
                    if ($barcode && BookCopy::where('barcode', $barcode)->exists()) {
                        continue;
                    }
                    $book->copies()->create(['barcode' => $barcode ?: BookCopy::newBarcodePlaceholder(), 'shelf' => $fit($item['shelf'], 255)]);
                    $copies++;
                }
            }
        });
    }

    /** The catalogue as MARC21 for Koha or any library system: ISO 2709 (.mrc) or MARCXML. */
    public function exportMarc(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'format' => ['nullable', 'in:mrc,xml'],
            'section' => ['nullable', 'integer'],
            'item_type' => ['nullable', 'string', 'max:10'],
            'branch' => ['nullable', 'string', 'max:10'],
        ]);
        $xml = ($data['format'] ?? 'mrc') === 'xml';
        $query = Book::query()->with('copies')->orderBy('id');
        if (! empty($data['section'])) {
            $query->where('section_id', $data['section']);
        }
        ActivityLog::record('library.export', 'صدّر فهرس المكتبة بصيغة MARC');

        return response()->streamDownload(function () use ($query, $xml, $data) {
            if ($xml) {
                echo MarcWriter::xmlOpen();
            }
            $query->chunkById(200, function ($books) use ($xml, $data) {
                foreach ($books as $book) {
                    $record = MarcMapper::fromBook($book, $data['item_type'] ?? 'BK', $data['branch'] ?? null);
                    echo $xml ? MarcWriter::xml($record) : MarcWriter::iso($record);
                }
            });
            if ($xml) {
                echo MarcWriter::xmlClose();
            }
        }, 'library-'.now()->format('Y-m-d').($xml ? '.xml' : '.mrc'), ['Content-Type' => $xml ? 'application/marcxml+xml; charset=UTF-8' : 'application/marc']);
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
