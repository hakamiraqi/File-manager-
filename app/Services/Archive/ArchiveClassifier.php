<?php

namespace App\Services\Archive;

use App\Models\ActivityLog;
use App\Models\ArchiveRule;
use App\Models\File;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The archive: reads a file's text (its own text or what OCR recognised), finds which categories
 * its words point to (صادر، وارد، أمر إداري…), and reads the letter's number and date.
 *
 * Pure PHP on text that is already in the database, so it costs next to nothing; the heavy part
 * (OCR of scans) stays in the off-hours OCR queue.
 */
class ArchiveClassifier
{
    /** The letterhead (number, date, the «الوارد» stamp) sits at the top; words there count more. */
    private const HEAD_CHARS = 700;

    private ?Collection $rules = null;

    public static function enabled(): bool
    {
        return (string) Setting::get('archive_enabled', '1') === '1';
    }

    /** Folds the spellings OCR and typists mix up: hamzas, ta marbuta, alef maqsura, tashkeel, digits. */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text; // tashkeel, tatweel
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    /**
     * A keyword as a whole word: «صادر» finds «الصادر»، «والصادرة»، «صادرة» but not «مصادر».
     */
    public static function pattern(string $keyword): string
    {
        $k = self::normalize(trim($keyword));
        if (preg_match('/^\p{Arabic}/u', $k)) {
            $k = preg_replace('/^(ال)(?=\p{Arabic}{2})/u', '', $k) ?? $k;
            $body = implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), explode(' ', $k)));

            return '/(?<![\p{L}\p{N}])(?:[وفبلك])?(?:ال)?'.$body.'(?:ه|ات|ين|ون|ان)?(?![\p{L}\p{N}])/u';
        }
        $body = implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), explode(' ', $k)));

        return '/(?<![\p{L}\p{N}])'.$body.'(?![\p{L}\p{N}])/u';
    }

    /**
     * Every enabled rule that the text matches, best first.
     *
     * @return list<array{rule: ArchiveRule, score: int, hits: list<string>}>
     */
    public function matches(string $text): array
    {
        $norm = self::normalize($text);
        if (trim($norm) === '') {
            return [];
        }
        $head = mb_substr($norm, 0, self::HEAD_CHARS);
        $found = [];
        // Word stems of the text, for keywords that OCR or the typist spelled a little differently.
        $stemsAll = self::stemCounts($norm);
        $stemsHead = self::stemCounts($head);

        foreach ($this->rules() as $rule) {
            $score = 0;
            $hits = [];
            $missing = false;
            $seen = [];
            foreach ($rule->keywordList() as $kw) {
                $re = self::pattern($kw);
                // «الوارد» and «وارد» are the same word here; count it once.
                if (isset($seen[$re])) {
                    continue;
                }
                $seen[$re] = true;
                $inHead = (int) preg_match_all($re, $head);
                $all = (int) preg_match_all($re, $norm);
                $similar = null;
                if ($all === 0 && ($similar = self::similarWord($kw, $stemsAll)) !== null) {
                    // A near spelling counts, a little less than the exact word.
                    [$all, $inHead] = [$stemsAll[$similar], $stemsHead[$similar] ?? 0];
                }
                if ($all === 0) {
                    $missing = true;

                    continue;
                }
                $hits[] = $similar !== null ? "{$kw} ≈ {$similar}" : $kw;
                // A phrase is stronger evidence than one word; repeats count, but only a few times.
                $weight = str_contains(trim($kw), ' ') ? 2 : 1;
                $points = $weight * (min($inHead, 3) * 3 + min($all - $inHead, 3));
                $score += $similar !== null ? max(1, intdiv($points, 2)) : $points;
            }
            if ($hits === [] || ($rule->match_mode === 'all' && $missing)) {
                continue;
            }
            $found[] = ['rule' => $rule, 'score' => $score, 'hits' => $hits];
        }

        usort($found, fn ($a, $b) => [$b['score'], $b['rule']->priority, $a['rule']->id] <=> [$a['score'], $a['rule']->priority, $b['rule']->id]);

        return $found;
    }

    /** The prefixes stripped to compare words: «والصادرة» → «صادره». */
    public static function stem(string $word): string
    {
        $w = preg_replace('/^(?:وال|بال|فال|كال|لل|ال)(?=\p{Arabic}{3})/u', '', $word) ?? $word;
        if ($w === $word) {
            $w = preg_replace('/^[وفبلك](?=\p{Arabic}{4})/u', '', $w) ?? $w;
        }

        return $w;
    }

    /** @return array<string, int> stem => how many times */
    public static function stemCounts(string $normalized): array
    {
        preg_match_all('/\p{Arabic}{2,}|[a-z]{3,}/u', $normalized, $m);
        $counts = [];
        foreach ($m[0] as $word) {
            $stem = self::stem($word);
            $counts[$stem] = ($counts[$stem] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * A word of the text that is one letter away from a single-word keyword of five letters or
     * more («الصادره» for «الصادرة» misread as «الصادره»، «وارده»، «تعميمم»…). Null when none.
     *
     * @param  array<string, int>  $stems
     */
    public static function similarWord(string $keyword, array $stems): ?string
    {
        $k = self::stem(self::normalize(trim($keyword)));
        if (str_contains($k, ' ') || mb_strlen($k) < 5) {
            return null;
        }
        $kl = mb_strlen($k);
        $best = null;
        foreach ($stems as $stem => $n) {
            $stem = (string) $stem;
            if (abs(mb_strlen($stem) - $kl) > 1 || $stem === $k) {
                continue;
            }
            if (self::distance($k, $stem) <= 1 && ($best === null || $n > $stems[$best])) {
                $best = $stem;
            }
        }

        return $best;
    }

    /** Levenshtein distance counted in letters (PHP's own counts bytes, two per Arabic letter). */
    private static function distance(string $a, string $b): int
    {
        $map = [];
        $toBytes = function (string $s) use (&$map): string {
            $out = '';
            foreach (mb_str_split($s) as $ch) {
                $map[$ch] ??= chr(count($map) % 255 + 1);
                $out .= $map[$ch];
            }

            return $out;
        };

        return levenshtein($toBytes($a), $toBytes($b));
    }

    /** «م/ طلب نقل خدمات» or «الموضوع: …» → the subject line of an official letter. */
    public static function subject(string $text): ?string
    {
        $head = mb_substr($text, 0, 2500);
        if (preg_match('/(?:^|\n|\s)(?:م\s*\/|الموضوع\s*[:\/]|الموضوع\s*-|subject\s*:)\s*([^\n]{3,200})/iu', $head, $m)) {
            $subject = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? $m[1], " \t.:-/");

            return $subject === '' ? null : mb_substr($subject, 0, 255);
        }

        return null;
    }

    /** «العدد: 123/4/56» or «رقم الكتاب ٨٩٠» → «123/4/56». */
    public static function reference(string $text): ?string
    {
        $head = mb_substr(self::normalize($text), 0, 1500);
        if (preg_match('/(?:العدد|الرقم|رقم الكتاب|رقم الامر|ref(?:erence)?\.?|no\.)\s*[:：\.\-]?\s*([a-z\p{Arabic}]{0,4}\s*[\/\-]?\s*\d[\d\/\-\. ]{0,24}\d|\d)/u', $head, $m)) {
            return trim(preg_replace('/\s+/u', '', $m[1]) ?? $m[1]);
        }

        return null;
    }

    /** «التاريخ: 20/11/2026» or «2026/11/20» → a date; the first plausible one in the head. */
    public static function date(string $text): ?Carbon
    {
        $head = mb_substr(self::normalize($text), 0, 1500);
        $labelled = preg_match('/(?:التاريخ|بتاريخ|date)\s*[:：\-]?\s*([0-9][0-9\/\-\.\s]{5,14}[0-9])/u', $head, $m) ? $m[1] : null;
        foreach (array_filter([$labelled, $head]) as $where) {
            if (preg_match('/(\d{4})\s*[\/\-\.]\s*(\d{1,2})\s*[\/\-\.]\s*(\d{1,2})/u', $where, $d)) {
                [$y, $mo, $da] = [(int) $d[1], (int) $d[2], (int) $d[3]];
            } elseif (preg_match('/(\d{1,2})\s*[\/\-\.]\s*(\d{1,2})\s*[\/\-\.]\s*(\d{4})/u', $where, $d)) {
                [$da, $mo, $y] = [(int) $d[1], (int) $d[2], (int) $d[3]];
            } else {
                continue;
            }
            if ($y >= 1950 && $y <= (int) now()->year + 1 && checkdate($mo, $da, $y)) {
                return Carbon::create($y, $mo, $da)->startOfDay();
            }
        }

        return null;
    }

    /**
     * Sorts one file. A category the user picked by hand is kept; only its number and date are
     * filled in. Returns the chosen rule, if any.
     */
    public function classify(File $file, bool $allowMove = true): ?ArchiveRule
    {
        if (! self::enabled()) {
            return null;
        }
        $text = (string) $file->content_text;
        $matches = $this->matches($text);
        $best = $matches[0]['rule'] ?? null;

        $data = [
            'archive_ref' => self::reference($text) ? mb_substr(self::reference($text), 0, 60) : null,
            'archive_date' => self::date($text)?->toDateString(),
            'archive_subject' => self::subject($text),
            'archive_classified_at' => now(),
        ];
        if (! $file->archive_manual) {
            $data['archive_rule_id'] = $best?->id;
            $data['archive_label'] = $best?->name;
            $data['archive_tags'] = $matches === [] ? null
                : mb_substr('|'.implode('|', array_map(fn ($m) => $m['rule']->name, $matches)).'|', 0, 500);
        }
        $file->forceFill($data)->save();

        if ($allowMove && $best && ! $file->archive_manual) {
            $this->fileAway($file, $best);
        }

        return $file->archive_manual ? $file->archiveRule : $best;
    }

    /** Moves the file into the category's folder, only when its owner can reach that folder. */
    private function fileAway(File $file, ArchiveRule $rule): void
    {
        $target = $rule->targetFolder;
        if (! $target || $target->trashed() || $file->folder_id === $target->id) {
            return;
        }
        try {
            $owner = $file->user;
            if (! $owner || ! $target->isAccessibleBy($owner)) {
                return;
            }
            $file->forceFill([
                'folder_id' => $target->id,
                'name' => \App\Services\UniqueNamer::forFile($file->user_id, $target->id, $file->name, $file->id),
            ])->save();
            ActivityLog::create([
                'user_id' => null,
                'action' => 'file.archived',
                'description' => "أرشفة تلقائية: نُقل الملف \"{$file->name}\" إلى \"{$target->name}\" ({$rule->name})",
                'subject_type' => $file->getMorphClass(),
                'subject_id' => $file->id,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Common words that say nothing about the kind of document. */
    private const STOPWORDS = [
        'في', 'من', 'الي', 'علي', 'عن', 'مع', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'الذين', 'او', 'ثم', 'قد', 'لقد', 'كل', 'بعد', 'قبل',
        'جامعه', 'تكريت', 'كليه', 'العراق', 'جمهوريه', 'العراقيه', 'وزاره', 'التعليم', 'العالي', 'والبحث', 'العلمي', 'قسم', 'شعبه',
        'السيد', 'السيده', 'الدكتور', 'المحترم', 'المحترمه', 'المحترمون', 'تحيه', 'طيبه', 'مع', 'التقدير', 'والاحترام', 'يرجي', 'ارجو',
        'العدد', 'التاريخ', 'الموافق', 'نسخه', 'منه', 'الي', 'اليكم', 'لديكم', 'لدينا', 'بتاريخ', 'المرقم', 'كتابكم', 'كتابنا', 'اشاره',
        'بناء', 'استنادا', 'لغرض', 'لاجراء', 'الاطلاع', 'واتخاذ', 'يلزم', 'ما', 'لا', 'نعم', 'ان', 'انه', 'كان', 'تم', 'وتم', 'هو', 'هي',
    ];

    /**
     * Words that come up again and again in the subjects (and letterheads) of files no category
     * caught: the admin can turn one into a new category with a click.
     *
     * @param  iterable<File>  $files
     * @return list<array{word: string, count: int, example: string|null}>
     */
    public static function suggestions(iterable $files, int $limit = 20): array
    {
        $stop = array_flip(array_map(fn ($w) => self::stem(self::normalize($w)), self::STOPWORDS));
        $known = [];
        foreach (ArchiveRule::all() as $rule) {
            foreach ($rule->keywordList() as $kw) {
                $known[self::stem(self::normalize($kw))] = true;
            }
        }
        $counts = [];
        $examples = [];
        foreach ($files as $file) {
            $source = $file->archive_subject ?: mb_substr((string) $file->content_text, 0, 400);
            $seen = [];
            foreach (array_keys(self::stemCounts(self::normalize($source))) as $stem) {
                if (mb_strlen($stem) < 3 || isset($stop[$stem]) || isset($known[$stem]) || isset($seen[$stem])) {
                    continue;
                }
                $seen[$stem] = true;
                $counts[$stem] = ($counts[$stem] ?? 0) + 1;
                $examples[$stem] ??= $file->archive_subject ?: $file->name;
            }
        }
        arsort($counts);

        return collect($counts)->filter(fn ($n) => $n >= 2)->take($limit)
            ->map(fn ($n, $w) => ['word' => (string) $w, 'count' => $n, 'example' => $examples[$w] ?? null])->values()->all();
    }

    /** @return Collection<int, ArchiveRule> */
    private function rules(): Collection
    {
        return $this->rules ??= ArchiveRule::with('targetFolder')->where('enabled', true)->orderByDesc('priority')->orderBy('id')->get();
    }

    public function forgetRules(): void
    {
        $this->rules = null;
    }
}
