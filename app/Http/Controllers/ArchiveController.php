<?php

namespace App\Http\Controllers;

use App\Jobs\ClassifyArchive;
use App\Models\ActivityLog;
use App\Models\ArchiveRule;
use App\Models\File;
use App\Models\Folder;
use App\Models\Setting;
use App\Services\Archive\ArchiveClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «الأرشيف»: documents, scans and drafts sorted by the words in their text (صادر، وارد، أمر إداري…),
 * searchable by category, letter number and date. Admins edit the categories and their words.
 */
class ArchiveController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $rule = $request->query('rule');
        $q = trim((string) $request->query('q', ''));
        $ref = trim((string) $request->query('ref', ''));
        $from = $request->date('from');
        $to = $request->date('to');

        $files = $this->visibleFiles($request)
            ->with(['folder:id,name', 'archiveRule:id,name,color'])
            ->when($rule === 'none', fn ($w) => $w->whereNotNull('archive_classified_at')->whereNull('archive_rule_id'))
            ->when($rule === 'all' || $rule === null || $rule === '', fn ($w) => $w->whereNotNull('archive_rule_id'))
            ->when(ctype_digit((string) $rule), function ($w) use ($rule) {
                $name = ArchiveRule::whereKey((int) $rule)->value('name');
                // The main category, or any other category the text also matched.
                $w->where(fn ($x) => $x->where('archive_rule_id', (int) $rule)
                    ->when($name, fn ($y) => $y->orWhere('archive_tags', 'like', '%|'.str_replace(['%', '_'], ['\%', '\_'], $name).'|%')));
            })
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('archive_subject', 'like', "%{$q}%")->orWhere('content_text', 'like', "%{$q}%")))
            ->when($ref !== '', fn ($w) => $w->where('archive_ref', 'like', '%'.ArchiveClassifier::normalize($ref).'%'))
            ->when($from, fn ($w) => $w->whereDate('archive_date', '>=', $from))
            ->when($to, fn ($w) => $w->whereDate('archive_date', '<=', $to))
            ->orderByRaw('archive_date IS NULL')->orderByDesc('archive_date')->orderByDesc('id')
            ->paginate(30)->withQueryString()
            ->through(fn (File $f) => [
                'id' => $f->id, 'name' => $f->name, 'extension' => $f->extension, 'human_size' => $f->human_size,
                'mime_type' => $f->mime_type, 'folder' => $f->folder?->only(['id', 'name']),
                'rule' => $f->archiveRule?->only(['id', 'name', 'color']),
                'tags' => array_values(array_filter(explode('|', (string) $f->archive_tags))),
                'ref' => $f->archive_ref, 'subject' => $f->archive_subject, 'date' => $f->archive_date?->toDateString(),
                'manual' => $f->archive_manual, 'ocr_status' => $f->ocr_status,
            ]);

        $counts = $this->visibleFiles($request)->whereNotNull('archive_rule_id')
            ->selectRaw('archive_rule_id, COUNT(*) as c')->groupBy('archive_rule_id')->pluck('c', 'archive_rule_id');

        return Inertia::render('Archive/Index', [
            'files' => $files,
            'filters' => ['rule' => $rule, 'q' => $q, 'ref' => $ref, 'from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'rules' => ArchiveRule::with('targetFolder:id,name')->orderByDesc('priority')->orderBy('name')->get()
                ->map(fn (ArchiveRule $r) => [...$r->only(['id', 'name', 'keywords', 'match_mode', 'color', 'target_folder_id', 'priority', 'enabled']),
                    'target_folder' => $r->targetFolder?->only(['id', 'name']), 'count' => (int) ($counts[$r->id] ?? 0)]),
            'stats' => [
                'unsorted' => $this->visibleFiles($request)->whereNotNull('archive_classified_at')->whereNull('archive_rule_id')->count(),
                'waiting_ocr' => $this->visibleFiles($request)->whereIn('ocr_status', ['pending', 'processing'])->count(),
            ],
            'enabled' => ArchiveClassifier::enabled(),
            // Words common to the files no category caught yet: candidates for new categories.
            'suggestions' => $user->isAdmin() ? ArchiveClassifier::suggestions(
                File::query()->whereNull('deleted_at')->whereNotNull('archive_classified_at')->whereNull('archive_rule_id')
                    ->latest('id')->limit(400)
                    ->select(['id', 'name', 'archive_subject'])->selectRaw('SUBSTR(content_text, 1, 400) as content_text')->get()
            ) : [],
            'isAdmin' => $user->isAdmin(),
        ]);
    }

    /** Picks the category by hand (or «بلا تصنيف»); the automatic sorting then leaves it alone. */
    public function setCategory(Request $request, File $file): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $file);
        $data = $request->validate([
            'rule_id' => ['nullable', 'integer', 'exists:archive_rules,id'],
            'auto' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('auto')) {
            $file->forceFill(['archive_manual' => false])->save();
            app(ArchiveClassifier::class)->classify($file, allowMove: false);
        } else {
            $rule = isset($data['rule_id']) ? ArchiveRule::find($data['rule_id']) : null;
            $file->forceFill([
                'archive_rule_id' => $rule?->id,
                'archive_label' => $rule?->name,
                'archive_tags' => $rule ? '|'.$rule->name.'|' : null,
                'archive_manual' => true,
                'archive_classified_at' => now(),
            ])->save();
        }
        ActivityLog::record('file.archive', "صنّف الملف \"{$file->name}\": ".($file->archive_label ?? 'بلا تصنيف'), $file);

        return $request->wantsJson()
            ? response()->json(['rule' => $file->archiveRule?->only(['id', 'name', 'color'])])
            : back()->with('success', 'تم تحديث تصنيف الملف.');
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $this->admin($request);
        $rule = ArchiveRule::create($this->ruleData($request));
        ActivityLog::record('archive.rule', "أضاف تصنيف الأرشفة \"{$rule->name}\"", $rule);

        return back()->with('success', 'تمت إضافة التصنيف.');
    }

    public function updateRule(Request $request, ArchiveRule $rule): RedirectResponse
    {
        $this->admin($request);
        $rule->update($this->ruleData($request));
        File::where('archive_rule_id', $rule->id)->update(['archive_label' => $rule->name]);
        ActivityLog::record('archive.rule', "عدّل تصنيف الأرشفة \"{$rule->name}\"", $rule);

        return back()->with('success', 'تم حفظ التصنيف.');
    }

    public function destroyRule(Request $request, ArchiveRule $rule): RedirectResponse
    {
        $this->admin($request);
        File::where('archive_rule_id', $rule->id)->update(['archive_label' => null, 'archive_rule_id' => null, 'archive_manual' => false]);
        ActivityLog::record('archive.rule', "حذف تصنيف الأرشفة \"{$rule->name}\"", $rule);
        $rule->delete();

        return back()->with('success', 'تم حذف التصنيف.');
    }

    /** «جرّب نصاً»: which categories a pasted text would go to, and the number and date found. */
    public function test(Request $request, ArchiveClassifier $classifier): JsonResponse
    {
        $this->admin($request);
        $text = (string) $request->validate(['text' => ['required', 'string', 'max:50000']])['text'];

        return response()->json([
            'matches' => array_map(fn ($m) => ['name' => $m['rule']->name, 'color' => $m['rule']->color, 'score' => $m['score'], 'hits' => $m['hits']], $classifier->matches($text)),
            'ref' => ArchiveClassifier::reference($text),
            'date' => ArchiveClassifier::date($text)?->toDateString(),
            'subject' => ArchiveClassifier::subject($text),
        ]);
    }

    /** Sorts again every file that has text (after the words changed), in the background. */
    public function reclassify(Request $request): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['enabled' => ['sometimes', 'boolean']]);
        if (array_key_exists('enabled', $data)) {
            Setting::set('archive_enabled', $data['enabled'] ? '1' : '0');

            return back()->with('success', $data['enabled'] ? 'تم تشغيل الأرشفة التلقائية.' : 'تم إيقاف الأرشفة التلقائية.');
        }
        ClassifyArchive::dispatch();

        return back()->with('success', 'بدأت إعادة تصنيف الملفات؛ تظهر النتائج خلال دقائق.');
    }

    private function ruleData(Request $request): array
    {
        $data = $request->validate(ArchiveRule::rules());
        if (! empty($data['target_folder_id'])) {
            Folder::findAccessible($request->user(), (int) $data['target_folder_id']);
        }
        $data['priority'] ??= 0;
        $data['keywords'] = collect(preg_split('/[\n\r،,]+/u', $data['keywords']))->map(fn ($k) => trim($k))->filter()->unique()->implode("\n");

        return $data;
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    /** Files the user may see: everything for an admin, else their own and those in folders assigned to them. */
    private function visibleFiles(Request $request): Builder
    {
        $user = $request->user();
        $query = File::query()->whereNull('deleted_at');
        if ($user->isAdmin()) {
            return $query;
        }
        $assigned = collect($user->assignedFolderIds())
            ->flatMap(fn ($id) => optional(Folder::whereNull('deleted_at')->find($id))->descendantIds() ?? [])->unique()->all();

        return $query->where(fn ($w) => $w->where('user_id', $user->id)->when($assigned, fn ($x) => $x->orWhereIn('folder_id', $assigned)));
    }
}
