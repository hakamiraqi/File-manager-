<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\Covers\CoverFinder;
use App\Services\Covers\CoverStore;
use App\Services\Pdf\PdfTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/** «الغلاف» on one file (2026-11-12): search and pick, upload a picture, or use a page of the file. */
class FileCoverController extends Controller
{
    public function search(Request $request, File $file, CoverFinder $finder): JsonResponse
    {
        $this->authorize('update', $file);
        $q = trim((string) $request->query('q', ''));
        $isbns = CoverFinder::isbns($file->content_text);
        if ($q === '') {
            $q = $isbns[0] ?? (CoverFinder::titleFromName((string) $file->name) ?? pathinfo((string) $file->name, PATHINFO_FILENAME));
        }
        try {
            $results = $finder->search(mb_substr($q, 0, 200), 12);
        } catch (Throwable $e) {
            return response()->json(['message' => CoverFinder::offline($e) ? 'لا يوجد اتصال بالإنترنت؛ يمكنك رفع صورة أو اختيار صفحة من الملف.' : 'تعذّر البحث الآن.'], 422);
        }

        return response()->json(['query' => $q, 'isbns' => $isbns, 'results' => $results]);
    }

    public function store(Request $request, File $file, CoverFinder $finder, PdfTools $pdf): JsonResponse
    {
        $this->authorize('update', $file);
        $d = $request->validate([
            'url' => ['nullable', 'url', 'max:500'],
            'source' => ['nullable', 'in:openlibrary,google'],
            'isbn' => ['nullable', 'string', 'max:20'],
            'image' => ['nullable', 'image', 'max:8192'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $jpeg = null;
        $source = 'upload';
        try {
            if (! empty($d['url'])) {
                abort_unless(CoverFinder::allowedUrl($d['url']), 422, 'رابط غير مسموح.');
                $jpeg = $finder->download($d['url']);
                $source = $d['source'] ?? 'openlibrary';
            } elseif ($request->hasFile('image')) {
                $jpeg = CoverFinder::toJpeg((string) file_get_contents($request->file('image')->getRealPath()));
            } elseif (! empty($d['page'])) {
                abort_unless(PdfTools::kindOf($file) === 'pdf' && $pdf->pdftoppm(), 422, 'هذا الخيار لملفات PDF فقط.');
                $jpeg = $this->page($file, $pdf, (int) $d['page']);
                $source = 'page';
            }
        } catch (Throwable $e) {
            if (CoverFinder::offline($e)) {
                return response()->json(['message' => 'لا يوجد اتصال بالإنترنت.'], 422);
            }
            throw $e;
        }
        if (! $jpeg) {
            return response()->json(['message' => 'تعذّر استعمال هذه الصورة غلافاً.'], 422);
        }
        CoverStore::apply($file, $jpeg, $source, 'manual', $d['isbn'] ?? null);

        return response()->json(['ok' => true, 'thumbnail' => route('files.thumbnail', $file->id).'?v='.substr(md5((string) $file->thumbnail_path), 0, 8)]);
    }

    public function destroy(File $file): JsonResponse
    {
        $this->authorize('update', $file);
        CoverStore::remove($file);

        return response()->json(['ok' => true]);
    }

    private function page(File $file, PdfTools $pdf, int $n): ?string
    {
        $dir = storage_path('app/tmp/covers-'.Str::random(8));
        @mkdir($dir, 0775, true);
        try {
            $r = Process::timeout(120)->run([$pdf->pdftoppm(), '-r', '110', '-jpeg', '-singlefile', '-f', (string) $n, '-l', (string) $n, $pdf->pdfPath($file), $dir.'/p']);
            $jpg = $dir.'/p.jpg';

            return $r->successful() && is_file($jpg) ? CoverFinder::toJpeg((string) file_get_contents($jpg)) : null;
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }
}
