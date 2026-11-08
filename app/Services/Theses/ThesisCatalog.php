<?php

namespace App\Services\Theses;

use App\Models\Folder;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Thesis folders as a catalogue (like the Iraqi Digital Repository): who may see which,
 * filters, duplicate-title detection and the institution's preset lists.
 */
class ThesisCatalog
{
    public const DEGREE_LABELS = ['diploma' => 'دبلوم عالي', 'master' => 'ماجستير', 'phd' => 'دكتوراه'];

    public const LANGUAGE_LABELS = ['ar' => 'العربية', 'en' => 'الإنكليزية', 'ku' => 'الكردية', 'other' => 'أخرى'];

    /** Ids of the thesis folders the viewer may see (null viewer = public ones only). */
    public function visibleIds(?User $viewer): array
    {
        $theses = Folder::where('folder_type', 'thesis')->pluck('id')->all();
        if ($viewer?->isAdmin()) {
            return $theses;
        }

        $all = Folder::get(['id', 'parent_id', 'user_id', 'is_public'])->keyBy('id');
        $assigned = $viewer ? array_flip($viewer->assignedFolderIds()) : [];

        return array_values(array_filter($theses, function ($id) use ($all, $assigned, $viewer) {
            if ($viewer && ($all[$id]->user_id ?? null) === $viewer->id) {
                return true;
            }
            for ($node = $all[$id] ?? null, $i = 0; $node && $i < 100; $node = $all[$node->parent_id] ?? null, $i++) {
                if ($node->is_public || isset($assigned[$node->id])) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function query(?User $viewer, array $filters = []): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));

        return Folder::whereIn('id', $this->visibleIds($viewer))
            ->when($filters['degree'] ?? null, fn ($w, $v) => $w->where('degree', $v))
            ->when($filters['year'] ?? null, fn ($w, $v) => $w->where('publication_year', $v))
            ->when($filters['department'] ?? null, fn ($w, $v) => $w->where('department', $v))
            ->when($filters['subject'] ?? null, fn ($w, $v) => $w->where('subject', $v))
            ->when($filters['language'] ?? null, fn ($w, $v) => $w->where('language', $v))
            ->when($q !== '', function ($w) use ($q, $filters) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $fields = match ($filters['field'] ?? 'all') {
                    'title' => ['thesis_title', 'name'],
                    'researcher' => ['researcher_name'],
                    'supervisor' => ['supervisor_name'],
                    'subject' => ['subject', 'keywords'],
                    default => ['thesis_title', 'name', 'researcher_name', 'supervisor_name', 'subject', 'keywords', 'abstract', 'department'],
                };
                $w->where(fn ($o) => collect($fields)->each(fn ($f) => $o->orWhere($f, 'like', $like)));
            });
    }

    /** Values present for each browse facet, with counts (within what the viewer may see). */
    public function facets(?User $viewer): array
    {
        $rows = Folder::whereIn('id', $this->visibleIds($viewer))->get(['degree', 'publication_year', 'department', 'subject', 'language']);
        $count = fn (string $field) => $rows->pluck($field)->filter(fn ($v) => filled($v))->countBy()->sortKeys()->all();

        return [
            'degree' => $count('degree'),
            'year' => collect($count('publication_year'))->sortKeysDesc()->all(),
            'department' => $count('department'),
            'subject' => $count('subject'),
            'language' => $count('language'),
            'total' => $rows->count(),
        ];
    }

    /** Preset lists from the settings, for pickers. */
    public function presets(): array
    {
        $list = fn (string $key) => collect(preg_split('/[,،\n]+/u', (string) Setting::get($key, '')))
            ->map(fn ($v) => trim($v))->filter()->unique()->values()->all();

        return [
            'university' => (string) Setting::get('institution_university', ''),
            'college' => (string) Setting::get('institution_college', ''),
            'departments' => $list('thesis_departments'),
            'subjects' => $list('thesis_subjects'),
        ];
    }

    /**
     * Theses whose title is the same or nearly the same (Arabic spelling variants,
     * diacritics and spacing ignored). The repository rejects duplicate titles too.
     */
    public function similar(string $title, ?int $exceptId = null, int $limit = 5): Collection
    {
        $needle = self::normalize($title);
        if (mb_strlen($needle) < 8) {
            return collect();
        }

        return Folder::where('folder_type', 'thesis')
            ->whereNotNull('thesis_title')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->get(['id', 'name', 'thesis_title', 'researcher_name', 'publication_year', 'degree'])
            ->map(function (Folder $f) use ($needle) {
                $other = self::normalize($f->thesis_title);
                similar_text($needle, $other, $pct);
                $f->setAttribute('similarity', $other === $needle ? 100 : (int) round($pct));

                return $f;
            })
            ->filter(fn ($f) => $f->similarity >= 85)
            ->sortByDesc('similarity')->take($limit)->values();
    }

    public static function normalize(?string $text): string
    {
        $t = mb_strtolower((string) $text);
        $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t);          // diacritics, tatweel
        $t = strtr($t, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي', 'ک' => 'ك', 'ی' => 'ي']);
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);

        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    /** One CSV/Excel row in the repository's field order. */
    public static function exportColumns(): array
    {
        return [
            'thesis_title' => 'عنوان الرسالة',
            'researcher_name' => 'اسم الباحث',
            'degree' => 'الدرجة',
            'supervisor_name' => 'المشرف',
            'university' => 'الجامعة',
            'college' => 'الكلية',
            'department' => 'القسم',
            'subject' => 'الموضوع العام',
            'keywords' => 'الكلمات المفتاحية',
            'publication_year' => 'سنة النشر',
            'defense_date' => 'تاريخ المناقشة',
            'language' => 'اللغة',
            'pages' => 'عدد الصفحات',
            'abstract' => 'المستخلص',
            'iqdr_receipt' => 'رقم وصل الإيداع',
        ];
    }
}
