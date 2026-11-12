<?php

namespace App\Http\Controllers;

use App\Http\Resources\FileResource;
use App\Jobs\ExtractFileText;
use App\Jobs\GenerateThumbnail;
use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Lectures\LectureCatalog;
use App\Services\Pdf\PdfToolException;
use App\Services\Pdf\PdfTools;
use App\Services\StorageQuotaService;
use App\Services\UniqueNamer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File as Fs;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «أدوات PDF» (2026-11-12): the tools page, the file picker and running a tool. What a tool
 * makes is saved as new files next to the first chosen file (the originals never change).
 */
class PdfToolController extends Controller
{
    /** Inputs bigger than this together are refused (a tool runs while the page waits). */
    private const MAX_INPUT_BYTES = 600 * 1024 * 1024;

    public function __construct(private readonly PdfTools $tools) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->canPerform('view'), 403);
        $ids = array_slice(array_map('intval', (array) $request->query('files', [])), 0, 50);
        $files = $ids ? $this->visibleFiles($user)->whereIn('id', $ids)->get()->sortBy(fn ($f) => array_search($f->id, $ids))->values() : collect();

        return Inertia::render('PdfTools/Index', [
            'capabilities' => $this->tools->capabilities(),
            'tool' => in_array($request->query('tool'), PdfTools::TOOLS, true) ? $request->query('tool') : null,
            'files' => $files->map(fn (File $f) => $this->brief($f))->values(),
            'canCreate' => $user->canPerform('upload'),
            'inApp' => (bool) $request->session()->get('in_app'),
        ]);
    }

    /** The picker: the reader's files of one kind, newest first, or matching a name. */
    public function files(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $kinds = array_intersect(explode(',', (string) $request->query('kinds', 'pdf')), ['pdf', 'image', 'office']);
        $ext = [];
        foreach ($kinds as $k) {
            $ext = array_merge($ext, match ($k) {
                'pdf' => ['pdf'],
                'image' => PdfTools::IMAGE_EXTENSIONS,
                'office' => ['doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp', 'ppsx'],
            });
        }

        $files = $this->visibleFiles($user)
            ->whereIn('extension', $ext)
            ->when($q !== '', fn (Builder $b) => $b->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%'))
            ->with('folder:id,name')
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get();

        return response()->json(['files' => $files->map(fn (File $f) => $this->brief($f) + ['folder_name' => $f->folder?->name])->values()]);
    }

    public function run(Request $request, string $tool, StorageQuotaService $quota): JsonResponse
    {
        abort_unless(in_array($tool, PdfTools::TOOLS, true), 404);
        $user = $request->user();
        abort_unless($user->canPerform('upload'), 403, 'لا تملك صلاحية إنشاء الملفات.');
        abort_unless($this->tools->capabilities()[$tool] ?? false, 422, 'هذه الأداة غير متوفرة على هذا الخادم.');

        $o = $request->validate($this->rules($tool));
        $files = $this->load($user, $o['files']);
        $this->checkKinds($tool, $files);
        if ($files->sum('size') > self::MAX_INPUT_BYTES) {
            throw ValidationException::withMessages(['files' => 'الملفات كبيرة جداً لهذه الأداة (الحد 600 ميغابايت معاً).']);
        }

        @set_time_limit(1800);
        $dir = storage_path('app/tmp/pdf-tools/'.Str::uuid());
        Fs::ensureDirectoryExists($dir);
        try {
            [$outputs, $message] = $this->make($tool, $files, $o, $dir, $request);
            $quota->assertHasSpace($user, (int) array_sum(array_map(fn ($x) => filesize($x['path']), $outputs)));
            $folderId = $this->targetFolder($user, $files->first());
            $made = array_map(fn ($x) => $this->store($user, $folderId, $x['path'], $x['name'], $tool, $quota), $outputs);
        } catch (PdfToolException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            Fs::deleteDirectory($dir);
        }

        return response()->json([
            'message' => $message,
            'files' => array_map(fn (File $f) => (new FileResource($f))->resolve($request), $made),
        ]);
    }

    // ------------------------------------------------------------------ the tools

    /** @return array{0: list<array{path:string,name:string}>, 1: ?string} */
    private function make(string $tool, Collection $files, array $o, string $dir, Request $request): array
    {
        $t = $this->tools;
        $first = $files->first();
        $base = Str::limit(pathinfo($first->name, PATHINFO_FILENAME), 150, '');
        $out = fn (string $suffix, string $ext = 'pdf') => ['path' => $dir.'/out-'.Str::random(8).'.'.$ext, 'name' => $base.($suffix !== '' ? " ({$suffix})" : '').'.'.$ext];

        switch ($tool) {
            case 'merge':
                $r = $out('مدمج');
                $t->merge($files->map(fn (File $f) => $t->asPdf($f, $dir))->all(), $r['path']);

                return [[$r], null];

            case 'split':
                $pdf = $t->pdfPath($first);
                $count = $t->pageCount($pdf);
                $ranges = $this->ranges($o, $count);
                $made = [];
                foreach ($ranges as $range) {
                    $r = $out('صفحات '.str_replace('z', (string) $count, $range));
                    $t->extract($pdf, $range, $r['path']);
                    $made[] = $r;
                }

                return [$made, null];

            case 'organize':
                // files[0] is the file being organised; any others are files whose pages were inserted.
                $sources = $files->map(fn (File $f) => $t->asPdf($f, $dir))->all();
                $counts = array_map(fn ($p) => $t->pageCount($p), $sources);
                $pages = array_map(fn ($p) => [(int) $p[0], (int) ($p[1] ?? 0), (int) ($p[2] ?? 0)], $o['pages']);
                foreach ($pages as $p) {
                    if ($p[0] !== 0 && ($p[0] < 1 || $p[0] > ($counts[$p[2]] ?? 0))) {
                        throw new PdfToolException('رقم صفحة خارج الملف.');
                    }
                }
                $r = $out('منظّم');
                $t->assemble($sources, $pages, $r['path'], $dir);

                return [[$r], null];

            case 'header_footer':
                $png = $dir.'/mark.png';
                $request->file('mark')->move($dir, 'mark.png');
                $r = $out('ترويسة');
                $t->watermark($t->pdfPath($first), $png, false, $o['pages'] ?? 'all', $r['path'], $dir);
                if (! empty($o['numbers'])) {
                    $numbered = $dir.'/numbered.pdf';
                    $t->numbers($r['path'], $o + ['position' => 'bottom-center', 'format' => 'n'], $numbered, $dir);
                    rename($numbered, $r['path']);
                }

                return [[$r], null];

            case 'crop':
                $r = $out('مقصوص');
                $n = $t->crop($t->pdfPath($first), [$o['top'] ?? 0, $o['right'] ?? 0, $o['bottom'] ?? 0, $o['left'] ?? 0], (bool) ($o['auto'] ?? false), $o['which'] ?? 'all', $r['path'], $dir);

                return [[$r], "قُصّت {$n} صفحة."];

            case 'redact':
                $boxes = [];
                foreach ($o['boxes'] as $b) {
                    $boxes[(int) $b['page']][] = [(float) $b['x'], (float) $b['y'], (float) $b['w'], (float) $b['h']];
                }
                $r = $out('مُخفى');
                $n = $t->redact($t->pdfPath($first), $boxes, $r['path'], $dir);

                return [[$r], "أُخفيت المناطق نهائياً في {$n} صفحة. نص هذه الصفحات صار صورة؛ يُقرأ من جديد بالتعرف على النصوص."];

            case 'repair':
                $r = $out('مُصلح');
                $how = $t->repair($first->disk()->path($first->path), $r['path']);

                return [[$r], $how === 'clean' ? 'لم توجد أخطاء في بنية الملف؛ حُفظت نسخة نظيفة منه.' : 'أُصلح الملف.'];

            case 'remove_blank':
                $pdf = $t->pdfPath($first);
                $count = $t->pageCount($pdf);
                $blank = $t->blankPages($pdf, $dir);
                if (! $blank) {
                    throw new PdfToolException('لا توجد صفحات فارغة في الملف.');
                }
                if (count($blank) >= $count) {
                    throw new PdfToolException('كل صفحات الملف فارغة.');
                }
                $keep = array_values(array_diff(range(1, $count), $blank));
                $r = $out('بدون الصفحات الفارغة');
                $t->organize($pdf, array_map(fn ($n) => [$n, 0], $keep), $r['path']);

                return [[$r], 'حُذفت '.count($blank).' صفحة فارغة: '.PdfTools::compactRange($blank).'.'];

            case 'extract_images':
                $r = $out('الصور', 'zip');
                $n = $t->extractImages($t->pdfPath($first), $r['path'], $dir);

                return [[$r], "{$n} صورة في ملف مضغوط واحد."];

            case 'rotate':
                $r = $out('مُدوّر');
                $t->rotate($t->pdfPath($first), (int) $o['degrees'], $o['which'] ?? 'all', $r['path']);

                return [[$r], null];

            case 'compress':
                $pdf = $t->pdfPath($first);
                $r = $out('مضغوط');
                $t->compress($pdf, $o['level'] ?? 'medium', $r['path']);
                $before = filesize($pdf);
                $after = filesize($r['path']);
                if ($after >= $before * 0.97) {
                    throw new PdfToolException('الملف مضغوط أصلاً؛ لم يصغر حجمه أكثر.');
                }

                return [[$r], 'صغر الحجم من '.$this->human($before).' إلى '.$this->human($after).' (−'.round(100 - $after * 100 / $before).'%).'];

            case 'protect':
                $r = $out('محمي');
                $t->protect($t->pdfPath($first), $o['password'], $o['deny'] ?? [], $r['path'], $dir);

                return [[$r], null];

            case 'unlock':
                $r = $out('بدون كلمة مرور');
                $t->unlock($first->disk()->path($first->path), (string) ($o['password'] ?? ''), $r['path'], $dir);

                return [[$r], null];

            case 'watermark':
                $png = $dir.'/mark.png';
                $request->file('mark')->move($dir, 'mark.png');
                $r = $out('علامة مائية');
                $t->watermark($t->pdfPath($first), $png, (bool) ($o['under'] ?? false), $o['pages'] ?? 'all', $r['path'], $dir);

                return [[$r], null];

            case 'numbers':
                $r = $out('مرقّم');
                $t->numbers($t->pdfPath($first), $o, $r['path'], $dir);

                return [[$r], null];

            case 'to_images':
                $fmt = $o['format'] ?? 'png';
                $r = $out('صور', 'zip');
                $n = $t->toImages($t->pdfPath($first), $fmt, (int) ($o['dpi'] ?? 150), $r['path'], $dir);

                return [[$r], "{$n} صورة في ملف مضغوط واحد."];

            case 'images_to_pdf':
                $r = $out($files->count() > 1 ? 'صور' : '');
                $t->imagesToPdf($files->map(fn (File $f) => $f->disk()->path($f->path))->all(), $r['path']);

                return [[$r], null];

            case 'office_to_pdf':
                $made = [];
                foreach ($files as $f) {
                    $r = ['path' => $dir.'/out-'.Str::random(8).'.pdf', 'name' => pathinfo($f->name, PATHINFO_FILENAME).'.pdf'];
                    $t->officeToPdf($f, $r['path']);
                    $made[] = $r;
                }

                return [$made, null];

            case 'to_word':
                $r = $out('', 'docx');
                $how = $t->toWord($first, $t->pdfPath($first), $r['path']);

                return [[$r], $how === 'text' ? 'حُوِّل النص فقرةً فقرة (التنسيق الأصلي لا يُنقل في هذه الطريقة).' : null];

            case 'to_text':
                $r = $out('', 'txt');
                $text = $t->toTextString($t->pdfPath($first));
                if (trim($text) === '') {
                    throw new PdfToolException('لا يوجد نص في الملف؛ إن كان صوراً ممسوحة فانتظر حتى يقرأه التعرف على النصوص.');
                }
                file_put_contents($r['path'], "\u{FEFF}".str_replace("\f", "\n\n", $text));

                return [[$r], null];
        }

        throw new PdfToolException('أداة غير معروفة.');
    }

    /**
     * The reader saves a changed copy of a PDF it has open (a filled-in form): the bytes come
     * from the browser and are stored as a new file next to the original.
     */
    public function saveCopy(Request $request, File $file, StorageQuotaService $quota): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('view', $file) && $user->canPerform('upload'), 403);
        $data = $request->validate([
            'pdf' => ['required', 'file', 'max:51200'],
            'kind' => ['nullable', 'in:filled'],
        ]);
        $upload = $request->file('pdf');
        $head = (string) file_get_contents($upload->getRealPath(), false, null, 0, 5);
        abort_unless($head === '%PDF-', 422, 'الملف ليس PDF.');
        $quota->assertHasSpace($user, (int) $upload->getSize());
        $dir = storage_path('app/tmp/pdf-tools/'.Str::uuid());
        Fs::ensureDirectoryExists($dir);
        try {
            $upload->move($dir, 'copy.pdf');
            $name = Str::limit(pathinfo($file->name, PATHINFO_FILENAME), 150, '').' (معبأ).pdf';
            $made = $this->store($user, $this->targetFolder($user, $file), $dir.'/copy.pdf', $name, 'copy', $quota);
        } finally {
            Fs::deleteDirectory($dir);
        }

        return response()->json(['file' => (new FileResource($made))->resolve($request)]);
    }

    private function rules(string $tool): array
    {
        $one = ['files' => ['required', 'array', 'size:1'], 'files.*' => ['integer']];
        $many = fn (int $min, int $max) => ['files' => ['required', 'array', "min:$min", "max:$max"], 'files.*' => ['integer']];

        return match ($tool) {
            'merge' => $many(2, 50),
            'split' => $one + [
                'mode' => ['required', 'in:ranges,every,each'],
                'ranges' => ['required_if:mode,ranges', 'nullable', 'string', 'max:500', 'regex:/^[\d\sz,\-]+$/'],
                'every' => ['required_if:mode,every', 'nullable', 'integer', 'min:1', 'max:10000'],
            ],
            'organize' => $many(1, 20) + ['pages' => ['required', 'array', 'min:1', 'max:20000'], 'pages.*' => ['array'], 'pages.*.0' => ['required', 'integer', 'min:0'], 'pages.*.1' => ['nullable', 'integer', 'in:0,90,180,270'], 'pages.*.2' => ['nullable', 'integer', 'min:0', 'max:19']],
            'header_footer' => $one + ['mark' => ['required', 'file', 'mimes:png', 'max:15360'], 'pages' => ['nullable', 'in:all,first,odd,even'], 'numbers' => ['nullable', 'boolean'],
                'position' => ['nullable', 'in:bottom-center,bottom-left,bottom-right,top-center,top-left,top-right'], 'format' => ['nullable', 'in:n,n/N,-n-,page'], 'size' => ['nullable', 'integer', 'min:6', 'max:36']],
            'crop' => $one + ['top' => ['nullable', 'numeric', 'min:0', 'max:300'], 'right' => ['nullable', 'numeric', 'min:0', 'max:300'], 'bottom' => ['nullable', 'numeric', 'min:0', 'max:300'], 'left' => ['nullable', 'numeric', 'min:0', 'max:300'],
                'auto' => ['nullable', 'boolean'], 'which' => ['nullable', 'in:all,odd,even,first']],
            'redact' => $one + ['boxes' => ['required', 'array', 'min:1', 'max:2000'], 'boxes.*.page' => ['required', 'integer', 'min:1'],
                'boxes.*.x' => ['required', 'numeric', 'between:0,1'], 'boxes.*.y' => ['required', 'numeric', 'between:0,1'], 'boxes.*.w' => ['required', 'numeric', 'between:0,1'], 'boxes.*.h' => ['required', 'numeric', 'between:0,1']],
            'repair', 'remove_blank', 'extract_images' => $one,
            'rotate' => $one + ['degrees' => ['required', 'in:90,180,270'], 'which' => ['nullable', 'in:all,odd,even']],
            'compress' => $one + ['level' => ['nullable', 'in:low,medium,high']],
            'protect' => $one + ['password' => ['required', 'string', 'min:1', 'max:128'], 'deny' => ['nullable', 'array'], 'deny.*' => ['in:print,copy,modify']],
            'unlock' => $one + ['password' => ['nullable', 'string', 'max:128']],
            'watermark' => $one + ['mark' => ['required', 'file', 'mimes:png', 'max:15360'], 'under' => ['nullable', 'boolean'], 'pages' => ['nullable', 'in:all,first,odd,even']],
            'numbers' => $one + [
                'position' => ['required', 'in:bottom-center,bottom-left,bottom-right,top-center,top-left,top-right'],
                'format' => ['required', 'in:n,n/N,-n-,page'],
                'start' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'from' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'size' => ['nullable', 'integer', 'min:6', 'max:36'],
            ],
            'to_images' => $one + ['format' => ['nullable', 'in:png,jpg'], 'dpi' => ['nullable', 'integer', 'min:50', 'max:300']],
            'images_to_pdf' => $many(1, 300),
            'office_to_pdf' => $many(1, 20),
            'to_word', 'to_text' => $one,
        };
    }

    private function checkKinds(string $tool, Collection $files): void
    {
        $caps = $this->tools->capabilities();
        foreach ($files as $f) {
            $kind = PdfTools::kindOf($f);
            $ok = match ($tool) {
                'merge' => $kind === 'pdf' || ($kind === 'image' && $caps['merge_images']) || ($kind === 'office' && $caps['merge_office']),
                'images_to_pdf' => $kind === 'image',
                'office_to_pdf' => $kind === 'office',
                // Pages inserted from other files may come from images or documents too.
                'organize' => $kind === 'pdf' || ($f !== $files->first() && (($kind === 'image' && $caps['merge_images']) || ($kind === 'office' && $caps['merge_office']))),
                default => $kind === 'pdf',
            };
            if (! $ok) {
                throw ValidationException::withMessages(['files' => '«'.$f->name.'» لا يناسب هذه الأداة.']);
            }
        }
    }

    /** split: the page ranges to cut, checked against the page count. @return list<string> */
    private function ranges(array $o, int $count): array
    {
        if ($count < 1) {
            throw new PdfToolException('الملف بلا صفحات.');
        }
        $list = [];
        if ($o['mode'] === 'each') {
            if ($count > 500) {
                throw new PdfToolException('الملف أكثر من 500 صفحة؛ قسّمه إلى أجزاء أكبر.');
            }
            for ($i = 1; $i <= $count; $i++) {
                $list[] = (string) $i;
            }
        } elseif ($o['mode'] === 'every') {
            $n = (int) $o['every'];
            for ($i = 1; $i <= $count; $i += $n) {
                $list[] = $i.'-'.min($count, $i + $n - 1);
            }
        } else {
            foreach (explode(',', (string) $o['ranges']) as $part) {
                $part = str_replace(' ', '', $part);
                if ($part === '') {
                    continue;
                }
                if (! preg_match('/^(\d+|z)(?:-(\d+|z))?$/', $part, $m)) {
                    throw new PdfToolException("المدى «{$part}» غير مفهوم. مثال: 1-3, 5, 8-z");
                }
                $a = $m[1] === 'z' ? $count : (int) $m[1];
                $b = isset($m[2]) ? ($m[2] === 'z' ? $count : (int) $m[2]) : $a;
                if ($a < 1 || $b < 1 || $a > $count || $b > $count) {
                    throw new PdfToolException("المدى «{$part}» خارج الملف (عدد صفحاته {$count}).");
                }
                $list[] = $a === $b ? (string) $a : "{$a}-{$b}";
            }
        }
        if (! $list || count($list) > 500) {
            throw new PdfToolException('حدّد صفحات للتقسيم.');
        }

        return $list;
    }

    // ------------------------------------------------------------------ files

    private function visibleFiles(User $user): Builder
    {
        $visible = app(LectureCatalog::class)->visibleFolderIds($user);

        return File::query()->whereNull('deleted_at')
            ->when($visible !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('user_id', $user->id)->orWhereIn('folder_id', $visible)));
    }

    private function load(User $user, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = File::whereIn('id', $ids)->whereNull('deleted_at')->get()->keyBy('id');
        $files = collect($ids)->map(fn ($id) => $found[$id] ?? abort(404, 'ملف غير موجود.'));
        foreach ($files as $f) {
            abort_unless($user->can('view', $f), 403);
        }

        return $files->values();
    }

    private function brief(File $f): array
    {
        return [
            'id' => $f->id, 'name' => $f->name, 'extension' => strtolower((string) $f->extension), 'size' => (int) $f->size,
            'kind' => PdfTools::kindOf($f), 'folder_id' => $f->folder_id, 'thumbnail' => (bool) $f->thumbnail_path,
        ];
    }

    private function targetFolder(User $user, File $source): ?int
    {
        if (! $source->folder_id) {
            return null;
        }
        try {
            Folder::findAccessible($user, $source->folder_id);

            return $source->folder_id;
        } catch (\Throwable) {
            return null;
        }
    }

    private function store(User $user, ?int $folderId, string $path, string $name, string $tool, StorageQuotaService $quota): File
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $disk = Storage::disk(config('filenest.disk'));
        $relative = "{$user->id}/".Str::uuid().'.'.$ext;
        $disk->makeDirectory((string) $user->id);
        // rename() fails across drives (Windows: the data folder may be on another disk).
        if (! @rename($path, $disk->path($relative)) && ! copy($path, $disk->path($relative))) {
            throw new PdfToolException('تعذّر حفظ النتيجة.');
        }
        $size = (int) filesize($disk->path($relative));

        $file = File::create([
            'user_id' => $user->id,
            'folder_id' => $folderId,
            'name' => UniqueNamer::forFile($user->id, $folderId, $name),
            'disk' => config('filenest.disk'),
            'path' => $relative,
            'extension' => $ext,
            'mime_type' => ['pdf' => 'application/pdf', 'zip' => 'application/zip', 'txt' => 'text/plain',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'][$ext]
                ?? (mime_content_type($disk->path($relative)) ?: 'application/octet-stream'),
            'size' => $size,
        ]);
        $quota->increment($user, $size);
        ActivityLog::record('file.uploaded', "أنشأ الملف \"{$file->name}\" بأدوات PDF", $file);
        if (in_array($ext, ['pdf', 'docx', 'txt'], true)) {
            ExtractFileText::dispatch($file->id);
            GenerateThumbnail::dispatch($file->id);
        }

        return $file;
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, round($bytes / 1024)).' KB';
    }
}
