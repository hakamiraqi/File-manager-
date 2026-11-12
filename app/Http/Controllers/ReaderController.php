<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\ReaderSignature;
use App\Models\ReaderState;
use App\Services\LibreOfficeConverter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The PDF reader (2026-11-12, by request: «all of WPS's features, free»): full screen, with
 * page views, thumbnails, contents, reading colours, auto-scroll, bookmarks, highlights, pen,
 * text, notes, a signature and printing. Word/Excel/PowerPoint open in it as PDF.
 * Everything a reader adds is theirs alone (reader_states) and never changes the file.
 */
class ReaderController extends Controller
{
    /** Notes on one file are capped so one runaway pen cannot fill the database. */
    private const MAX_ANNOTATIONS_BYTES = 4_000_000;

    public function show(Request $request, File $file, LibreOfficeConverter $office): Response
    {
        $this->authorize('view', $file);
        abort_unless($request->user()->canPerform('view'), 403);
        $src = $this->source($file, $office);
        abort_if($src === null, 422, 'هذا النوع من الملفات لا يُفتح في القارئ.');

        return Inertia::render('Reader/Show', [
            'file' => ['id' => $file->id, 'name' => $file->name, 'folder_id' => $file->folder_id, 'extension' => $file->extension],
            'src' => $src,
            'state' => $this->stateFor($request, $file),
            'signature' => ReaderSignature::where('user_id', $request->user()->id)->value('strokes'),
            'canDownload' => $request->user()->canPerform('download'),
            'inApp' => (bool) $request->session()->get('in_app'),
        ]);
    }

    public function state(Request $request, File $file): JsonResponse
    {
        $this->authorize('view', $file);

        return response()->json([
            'state' => $this->stateFor($request, $file),
            'signature' => ReaderSignature::where('user_id', $request->user()->id)->value('strokes'),
        ]);
    }

    public function save(Request $request, File $file): JsonResponse
    {
        $this->authorize('view', $file);
        $data = $request->validate([
            'last_page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'pages' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'bookmarks' => ['sometimes', 'array', 'max:1000'],
            'bookmarks.*.page' => ['required', 'integer', 'min:1'],
            'bookmarks.*.title' => ['nullable', 'string', 'max:200'],
            'annotations' => ['sometimes', 'array', 'max:20000'],
            'annotations.*.type' => ['required', 'string', 'in:highlight,underline,strike,ink,text,note,sign'],
            'annotations.*.page' => ['required', 'integer', 'min:1'],
        ]);
        // Validation keeps only the checked keys (type, page); each note keeps all of its own.
        if (isset($data['annotations'])) {
            $data['annotations'] = array_values(array_filter((array) $request->input('annotations'), 'is_array'));
        }
        if (isset($data['annotations']) && strlen(json_encode($data['annotations'])) > self::MAX_ANNOTATIONS_BYTES) {
            return response()->json(['message' => 'الملاحظات على هذا الملف كثيرة جداً.'], 422);
        }
        if (isset($data['bookmarks'])) {
            $data['bookmarks'] = collect($data['bookmarks'])->map(fn ($b) => ['page' => (int) $b['page'], 'title' => (string) ($b['title'] ?? '')])->unique('page')->sortBy('page')->values()->all();
        }

        $state = ReaderState::updateOrCreate(['user_id' => $request->user()->id, 'file_id' => $file->id], $data);

        return response()->json(['saved' => true, 'updated_at' => $state->updated_at?->toIso8601String()]);
    }

    public function saveSignature(Request $request): JsonResponse
    {
        $data = $request->validate([
            'strokes' => ['required', 'array', 'min:1', 'max:200'],
            'strokes.*' => ['array', 'max:4000'],
        ]);
        abort_if(strlen(json_encode($data['strokes'])) > 300_000, 422, 'التوقيع كبير جداً.');
        ReaderSignature::updateOrCreate(['user_id' => $request->user()->id], ['strokes' => $data['strokes']]);

        return response()->json(['saved' => true]);
    }

    public function forgetSignature(Request $request): JsonResponse
    {
        ReaderSignature::where('user_id', $request->user()->id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** What the reader loads: the PDF itself (its searchable copy if made), or an office file as PDF. */
    private function source(File $file, LibreOfficeConverter $office): ?string
    {
        $ext = strtolower((string) $file->extension);
        if ($file->mime_type === 'application/pdf' || $ext === 'pdf') {
            return route('files.preview', $file->id);
        }
        if ($office->available() && $office->supports($ext)) {
            return route('files.document-preview', $file->id);
        }

        return null;
    }

    private function stateFor(Request $request, File $file): array
    {
        $s = ReaderState::where('user_id', $request->user()->id)->where('file_id', $file->id)->first();

        return [
            'last_page' => $s->last_page ?? 1,
            'bookmarks' => $s->bookmarks ?? [],
            'annotations' => $s->annotations ?? [],
        ];
    }
}
