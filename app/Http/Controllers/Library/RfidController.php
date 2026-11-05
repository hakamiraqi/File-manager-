<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Library\BookCopy;
use App\Models\Library\LibrarySection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * RFID tools: bind tags to copies (scan the barcode, then the tag) and shelf inventory
 * (scan everything on a section's shelves, compare with what should be there).
 */
class RfidController extends Controller
{
    public function page(): Response
    {
        return Inertia::render('Library/Manage/Rfid', [
            'sections' => LibrarySection::options(),
            'untagged' => BookCopy::whereNull('rfid_tag')->count(),
            'tagged' => BookCopy::whereNotNull('rfid_tag')->count(),
        ]);
    }

    /** Copies still without a tag, in barcode order, so a librarian can work through a section. */
    public function untagged(Request $request): JsonResponse
    {
        $request->validate(['section_id' => ['nullable', 'integer', 'exists:library_sections,id']]);

        $copies = BookCopy::with('book:id,title,authors,section_id')
            ->whereNull('rfid_tag')
            ->when($request->integer('section_id'), function ($q, $id) {
                $ids = LibrarySection::findOrFail($id)->descendantIds();
                $q->whereHas('book', fn ($b) => $b->whereIn('section_id', $ids));
            })
            ->orderBy('barcode')
            ->limit(200)
            ->get();

        return response()->json($copies->map(fn (BookCopy $c) => $this->copyRow($c)));
    }

    /** Looks up a scanned barcode or tag. */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:128']]);
        $copy = BookCopy::findByCode($request->string('code'));
        if (! $copy) {
            throw ValidationException::withMessages(['code' => 'لا توجد نسخة بهذا الرمز.']);
        }

        return response()->json(['copy' => $this->copyRow($copy->load('book:id,title,authors,section_id'))]);
    }

    /** Binds a tag to a copy. A tag already on another copy is moved only when asked. */
    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'copy_id' => ['required', 'integer', 'exists:book_copies,id'],
            'tag' => ['required', 'string', 'max:128'],
            'move' => ['sometimes', 'boolean'],
        ]);
        $tag = trim($data['tag']);
        $copy = BookCopy::findOrFail($data['copy_id']);

        if ($tag === $copy->barcode) {
            throw ValidationException::withMessages(['tag' => 'هذا باركود النسخة نفسها، امسح شريحة RFID.']);
        }
        // A barcode scanned here means the librarian moved on to another copy.
        $byBarcode = BookCopy::with('book:id,title,authors,section_id')->where('barcode', $tag)->first();
        if ($byBarcode) {
            return response()->json(['switch' => $this->copyRow($byBarcode)], 409);
        }

        $other = BookCopy::with('book:id,title')->where('rfid_tag', $tag)->where('id', '!=', $copy->id)->first();
        if ($other && ! ($data['move'] ?? false)) {
            return response()->json([
                'conflict' => true,
                'message' => "هذه الشريحة مربوطة بالنسخة {$other->barcode} ({$other->book?->title}).",
            ], 409);
        }
        $other?->update(['rfid_tag' => null]);
        $copy->update(['rfid_tag' => $tag]);

        ActivityLog::record('library.rfid', "ربط شريحة RFID بالنسخة {$copy->barcode}");

        return response()->json(['copy' => $this->copyRow($copy->load('book:id,title,authors,section_id'))]);
    }

    public function unlink(BookCopy $copy): JsonResponse
    {
        $copy->update(['rfid_tag' => null]);

        return response()->json(['ok' => true]);
    }

    /**
     * Compares the codes scanned on a section's shelves with the copies that should be there.
     * Loaned copies are expected to be away; held ones are expected at the desk.
     */
    public function inventory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'section_id' => ['required', 'integer', 'exists:library_sections,id'],
            'shelf' => ['nullable', 'string', 'max:255'],
            'codes' => ['present', 'array', 'max:5000'],
            'codes.*' => ['string', 'max:128'],
        ]);
        $section = LibrarySection::findOrFail($data['section_id']);
        $sectionIds = $section->descendantIds();
        $shelf = trim((string) ($data['shelf'] ?? ''));
        $codes = collect($data['codes'])->map(fn ($c) => trim($c))->filter()->unique()->values();

        $inScope = fn (BookCopy $c) => in_array($c->book?->section_id, $sectionIds, true)
            && ($shelf === '' || trim((string) $c->shelf) === $shelf);

        $scanned = BookCopy::with('book:id,title,authors,section_id')
            ->where(fn ($q) => $q->whereIn('barcode', $codes)->orWhereIn('rfid_tag', $codes))
            ->get();
        $known = $scanned->flatMap(fn ($c) => array_filter([$c->barcode, $c->rfid_tag]))->all();

        $expected = BookCopy::with('book:id,title,authors,section_id')
            ->whereHas('book', fn ($b) => $b->whereIn('section_id', $sectionIds))
            ->when($shelf !== '', fn ($q) => $q->where('shelf', $shelf))
            ->get();
        $scannedIds = $scanned->pluck('id')->all();

        $paths = collect(LibrarySection::options())->pluck('path', 'id');
        $row = fn (BookCopy $c) => $this->copyRow($c) + ['section_path' => $paths[$c->book?->section_id] ?? null];

        return response()->json([
            'found' => $scanned->filter($inScope)->map($row)->values(),
            'misplaced' => $scanned->reject($inScope)->map($row)->values(),
            'unknown' => $codes->reject(fn ($c) => in_array($c, $known, true))->values(),
            'missing' => $expected->whereNotIn('id', $scannedIds)
                ->whereIn('status', [BookCopy::AVAILABLE, BookCopy::DAMAGED])->map($row)->values(),
            'away' => $expected->whereNotIn('id', $scannedIds)
                ->whereIn('status', [BookCopy::LOANED, BookCopy::ON_HOLD])->map($row)->values(),
            'expected' => $expected->whereNotIn('status', [BookCopy::LOST])->count(),
        ]);
    }

    /** After an inventory: copies not found on the shelf can be marked lost, or found ones back to available. */
    public function mark(Request $request): JsonResponse
    {
        $data = $request->validate([
            'copy_ids' => ['required', 'array', 'max:5000'],
            'copy_ids.*' => ['integer'],
            'status' => ['required', Rule::in([BookCopy::LOST, BookCopy::AVAILABLE])],
        ]);

        $from = $data['status'] === BookCopy::LOST ? [BookCopy::AVAILABLE, BookCopy::DAMAGED] : [BookCopy::LOST];
        $count = BookCopy::whereIn('id', $data['copy_ids'])->whereIn('status', $from)->update(['status' => $data['status']]);

        ActivityLog::record('library.inventory', $data['status'] === BookCopy::LOST
            ? "الجرد: تعليم {$count} نسخة كمفقودة"
            : "الجرد: إرجاع {$count} نسخة مفقودة إلى المتاح");

        return response()->json(['updated' => $count]);
    }

    private function copyRow(BookCopy $c): array
    {
        return [
            'id' => $c->id,
            'barcode' => $c->barcode,
            'rfid_tag' => $c->rfid_tag,
            'shelf' => $c->shelf,
            'status' => $c->status,
            'title' => $c->book?->title,
            'authors' => $c->book?->authors,
        ];
    }
}
