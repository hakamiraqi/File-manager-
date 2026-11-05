<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Folder;
use App\Models\RolePermission;
use App\Models\Setting;
use App\Services\Library\SpreadsheetReader;
use App\Services\Theses\ThesisCatalog;
use App\Services\UniqueNamer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** «الرسائل والأطاريح»: browse thesis folders by degree, year, department and subject. */
class ThesisController extends Controller
{
    private const FILTERS = ['q', 'field', 'degree', 'year', 'department', 'subject', 'language'];

    public function __construct(private ThesisCatalog $catalog) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user && ! $this->guestsAllowed()) {
            return redirect()->route('login');
        }

        $filters = $request->only(self::FILTERS);
        $theses = $this->catalog->query($user, $filters)
            ->orderByDesc('publication_year')->orderBy('thesis_title')
            ->paginate(20)->withQueryString()
            ->through(fn (Folder $f) => $this->card($f, $user !== null));

        return Inertia::render('Theses/Index', [
            'theses' => $theses,
            'filters' => $filters,
            'facets' => $this->catalog->facets($user),
            'presets' => $this->catalog->presets(),
            'degreeLabels' => ThesisCatalog::DEGREE_LABELS,
            'languageLabels' => ThesisCatalog::LANGUAGE_LABELS,
            'canImport' => (bool) $user?->canPerform('new_folder'),
            'oaiEnabled' => Setting::get('oai_enabled', '1') === '1',
        ]);
    }

    /** Titles that look like this one, to warn before a duplicate is saved. */
    public function similar(Request $request): JsonResponse
    {
        $request->validate(['title' => ['required', 'string', 'max:500'], 'except' => ['nullable', 'integer']]);

        return response()->json($this->catalog->similar($request->string('title'), $request->integer('except') ?: null)
            ->map(fn (Folder $f) => [
                'id' => $f->id, 'title' => $f->thesis_title, 'researcher' => $f->researcher_name,
                'year' => $f->publication_year, 'degree' => $f->degree, 'similarity' => $f->similarity,
            ]));
    }

    /** The current list as CSV in the repository's field order (opens in Excel). */
    public function export(Request $request): StreamedResponse
    {
        $theses = $this->catalog->query($request->user(), $request->only(self::FILTERS))->orderBy('thesis_title')->get();
        $columns = ThesisCatalog::exportColumns();

        return response()->streamDownload(function () use ($theses, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [...array_values($columns), 'رابط المجلد']);
            foreach ($theses as $t) {
                fputcsv($out, [...array_map(fn ($field) => $this->exportValue($t, $field), array_keys($columns)), route('dashboard', ['folder' => $t->id])]);
            }
            fclose($out);
        }, 'theses-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values(ThesisCatalog::exportColumns()));
            fputcsv($out, ['أثر المضمضة بالكلورهيكسيدين على اللويحة الجرثومية', 'أحمد علي حسن', 'ماجستير', 'أ.د. محمد جاسم', '', '', 'طب الأسنان الوقائي', 'طب الأسنان', 'كلورهيكسيدين، لويحة', '2024', '2024-06-15', 'العربية', '120', '', '']);
            fclose($out);
        }, 'theses-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Old theses from Excel/CSV: one thesis folder per row, duplicates skipped. */
    public function import(Request $request, SpreadsheetReader $reader): RedirectResponse
    {
        $user = $request->user();
        if (! $user->canPerform('new_folder')) {
            throw new AuthorizationException('لا تملك صلاحية إنشاء مجلدات جديدة.');
        }
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:xlsx,csv,txt'],
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
        ], ['file.mimes' => 'الملف يجب أن يكون Excel (.xlsx) أو CSV.']);

        $parentId = $request->integer('parent_id') ?: null;
        if ($parentId) {
            Folder::findAccessible($user, $parentId);
        }

        $file = $request->file('file');
        $rows = $reader->read($file->getRealPath(), $file->getClientOriginalExtension());
        if (count($rows) < 2) {
            return back()->withErrors(['file' => 'الملف فارغ أو لا يحتوي صف عناوين للأعمدة.']);
        }
        $map = $this->mapHeader(array_shift($rows));
        if (! isset($map['thesis_title'])) {
            return back()->withErrors(['file' => 'لم أجد عمود «عنوان الرسالة» في الصف الأول.']);
        }

        $existing = Folder::where('folder_type', 'thesis')->pluck('thesis_title')
            ->map(fn ($t) => ThesisCatalog::normalize($t))->flip();
        $added = 0;
        $skipped = [];

        DB::transaction(function () use ($rows, $map, $user, $parentId, &$existing, &$added, &$skipped) {
            foreach ($rows as $i => $row) {
                $data = [];
                foreach ($map as $field => $col) {
                    $data[$field] = trim((string) ($row[$col] ?? ''));
                }
                if (($data['thesis_title'] ?? '') === '') {
                    continue;
                }
                $key = ThesisCatalog::normalize($data['thesis_title']);
                if (isset($existing[$key])) {
                    $skipped[] = $i + 2;

                    continue;
                }
                $existing[$key] = true;

                $data = $this->cleanImported($data);
                $name = Str::limit($data['thesis_title'], 120, '…');
                Folder::create(Folder::withInstitutionDefaults([
                    'user_id' => $user->id,
                    'parent_id' => $parentId,
                    'name' => UniqueNamer::forFolder($user->id, $parentId, $name),
                    'folder_type' => 'thesis',
                ] + $data));
                $added++;
            }
        });

        ActivityLog::record('thesis.imported', "استورد {$added} رسالة من ملف");

        $message = "تمت إضافة {$added} رسالة.";
        if ($skipped) {
            $message .= ' تخطّيت '.count($skipped).' مكرّرة (الصفوف: '.implode('، ', array_slice($skipped, 0, 15)).(count($skipped) > 15 ? '…' : '').').';
        }

        return back()->with('success', $message);
    }

    private function guestsAllowed(): bool
    {
        return Setting::get('guest_access_enabled', false) && RolePermission::allows('guest', 'view');
    }

    private function card(Folder $f, bool $signedIn): array
    {
        return [
            'id' => $f->id,
            'title' => $f->thesis_title ?: $f->name,
            'researcher' => $f->researcher_name,
            'supervisor' => $f->supervisor_name,
            'degree' => $f->degree,
            'year' => $f->publication_year,
            'department' => $f->department,
            'subject' => $f->subject,
            'keywords' => $f->keywords,
            'abstract' => $f->abstract,
            'language' => $f->language,
            'pages' => $f->pages,
            'university' => $f->university,
            'college' => $f->college,
            'iqdr_receipt' => $signedIn ? $f->iqdr_receipt : null,
            'url' => $signedIn ? route('dashboard', ['folder' => $f->id]) : route('guest.show', $f->id),
        ];
    }

    private function exportValue(Folder $f, string $field): string
    {
        return match ($field) {
            'degree' => ThesisCatalog::DEGREE_LABELS[$f->degree] ?? '',
            'language' => ThesisCatalog::LANGUAGE_LABELS[$f->language] ?? '',
            'defense_date' => $f->defense_date?->format('Y-m-d') ?? '',
            default => (string) $f->{$field},
        };
    }

    /** Header cell → field, by Arabic label or field name. */
    private function mapHeader(array $header): array
    {
        $byLabel = [];
        foreach (ThesisCatalog::exportColumns() as $field => $label) {
            $byLabel[ThesisCatalog::normalize($label)] = $field;
            $byLabel[ThesisCatalog::normalize(str_replace('_', ' ', $field))] = $field;
        }
        $byLabel += [
            ThesisCatalog::normalize('العنوان') => 'thesis_title', 'title' => 'thesis_title',
            ThesisCatalog::normalize('الباحث') => 'researcher_name', ThesisCatalog::normalize('اسم المؤلف') => 'researcher_name', 'author' => 'researcher_name',
            ThesisCatalog::normalize('السنة') => 'publication_year', 'year' => 'publication_year',
            ThesisCatalog::normalize('الموضوع') => 'subject',
        ];

        $map = [];
        foreach ($header as $col => $label) {
            $field = $byLabel[ThesisCatalog::normalize($label)] ?? null;
            if ($field && ! isset($map[$field])) {
                $map[$field] = $col;
            }
        }

        return $map;
    }

    private function cleanImported(array $data): array
    {
        if (isset($data['degree'])) {
            $d = ThesisCatalog::normalize($data['degree']);
            $data['degree'] = match (true) {
                str_contains($d, 'دكتوراه') || str_contains($d, 'phd') || str_contains($d, 'doctor') => 'phd',
                str_contains($d, 'ماجستير') || str_contains($d, 'master') => 'master',
                str_contains($d, 'دبلوم') || str_contains($d, 'diploma') => 'diploma',
                default => null,
            };
        }
        if (isset($data['language'])) {
            $l = ThesisCatalog::normalize($data['language']);
            $data['language'] = match (true) {
                $l === '' => null,
                str_contains($l, 'عرب') || $l === 'ar' || str_contains($l, 'arab') => 'ar',
                str_contains($l, 'انكليز') || str_contains($l, 'انجليز') || $l === 'en' || str_contains($l, 'engl') => 'en',
                str_contains($l, 'كرد') || $l === 'ku' => 'ku',
                default => 'other',
            };
        }
        if (isset($data['defense_date'])) {
            try {
                $data['defense_date'] = $data['defense_date'] === '' ? null : Carbon::parse($data['defense_date'])->toDateString();
            } catch (\Throwable) {
                $data['defense_date'] = null;
            }
        }
        if (isset($data['pages'])) {
            $data['pages'] = ctype_digit($data['pages']) ? (int) $data['pages'] : null;
        }
        foreach ($data as $k => $v) {
            if ($v === '') {
                $data[$k] = null;
            }
            if (is_string($data[$k]) && $k !== 'abstract') {
                $data[$k] = Str::limit($data[$k], $k === 'keywords' ? 1000 : 255, '');
            }
        }

        return $data;
    }
}
