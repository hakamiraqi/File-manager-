<?php

namespace App\Services\Exams;

use App\Models\Exams\Question;

/**
 * Turns an Excel/CSV sheet, an Aiken or GIFT text file, or a Moodle XML export into questions in the editor's shape
 * (texts in the exam's first language). The editor's own checks run on the result.
 *
 * Sheet columns (Arabic or English headers): النوع | السؤال | الخيار 1..8 | الإجابة | الدرجة | التغذية الراجعة
 *   الإجابة: the letter(s) or number(s) of the right choice(s) «أ» «A» «2» «1،3»,
 *            or the accepted answers for a short question «بغداد|بغدادُ», or the number for a numeric one «3.14±0.01».
 */
class QuestionImporter
{
    private const LETTERS = ['أ' => 1, 'ا' => 1, 'ب' => 2, 'ج' => 3, 'د' => 4, 'ه' => 5, 'هـ' => 5, 'و' => 6, 'ز' => 7, 'ح' => 8];

    private const TYPE_WORDS = [
        'single' => ['single', 'mcq', 'اختيار', 'اختيار من متعدد', 'متعدد الخيارات'],
        'multiple' => ['multiple', 'multi', 'متعدد', 'عدة اجابات', 'عدة إجابات'],
        'true_false' => ['true_false', 'truefalse', 'tf', 'صح وخطأ', 'صح خطأ', 'صح/خطأ', 'صواب وخطأ'],
        'short' => ['short', 'قصيرة', 'اجابة قصيرة', 'إجابة قصيرة'],
        'numeric' => ['numeric', 'number', 'رقمي', 'رقم'],
        'cloze' => ['cloze', 'فراغ', 'املأ الفراغ', 'املا الفراغ'],
        'essay' => ['essay', 'مقالي', 'مقال'],
        'description' => ['description', 'نص', 'نص توضيحي'],
    ];

    public const EXTENSIONS = ['xlsx', 'csv', 'txt', 'gift', 'xml', 'docx'];

    /** Any of the accepted files: .txt is GIFT when it looks like it, Aiken otherwise. */
    public function fromFile(string $path, string $ext, string $lang, \App\Services\SpreadsheetReader $reader): array
    {
        if (in_array($ext, ['xlsx', 'csv'], true)) {
            return $this->fromRows($reader->read($path, $ext), $lang);
        }
        // Word: the questions written in Aiken or GIFT form, one paragraph per line.
        $text = $ext === 'docx' ? self::docxText($path) : (string) file_get_contents($path);
        if ($ext === 'xml') {
            return $this->fromMoodleXml($text, $lang);
        }

        return $ext === 'gift' || self::looksLikeGift($text) ? $this->fromGift($text, $lang) : $this->fromAiken($text, $lang);
    }

    /** A Word file's text: each paragraph on its own line, tabs and line breaks kept. */
    public static function docxText(string $path): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        $xml = preg_replace(['#<w:tab/>#', '#<w:br[^>]*/>#', '#</w:p>#'], ["\t", "\n", "\n"], $xml);

        return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    public static function looksLikeGift(string $text): bool
    {
        return preg_match('/\{\s*(?:[=~#]|T(?:RUE)?\s*\}|F(?:ALSE)?\s*\}|\})/u', $text) === 1;
    }

    /** @return array{questions: array, skipped: array<int, string>} */
    public function fromRows(array $rows, string $lang): array
    {
        if (count($rows) < 2) {
            return ['questions' => [], 'skipped' => [1 => 'الملف فارغ أو بلا صف عناوين.']];
        }
        $map = $this->mapHeader(array_shift($rows));
        if (! isset($map['body'])) {
            return ['questions' => [], 'skipped' => [1 => 'لم أجد عمود «السؤال» في الصف الأول.']];
        }

        $questions = [];
        $skipped = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $cell = fn (string $key) => isset($map[$key]) ? trim((string) ($row[$map[$key]] ?? '')) : '';
            $body = $cell('body');
            if ($body === '') {
                continue;
            }
            $choices = [];
            foreach ($map['choices'] ?? [] as $col) {
                $v = trim((string) ($row[$col] ?? ''));
                if ($v !== '') {
                    $choices[] = $v;
                }
            }
            $answer = $cell('answer');
            $type = $this->type($cell('type'), $choices, $answer);
            if ($type === null) {
                $skipped[$line] = 'نوع غير معروف: '.$cell('type');

                continue;
            }
            $points = $cell('points') === '' ? 1 : (Grader::toNumber($cell('points')) ?? 1);

            $q = ['id' => null, 'type' => $type, 'body' => [$lang => $body], 'points' => $points, 'settings' => [], 'choices' => [],
                'feedback' => $cell('feedback') === '' ? [] : [$lang => $cell('feedback')]];

            if (in_array($type, ['single', 'multiple', 'true_false'], true)) {
                if ($type === 'true_false' && $choices === []) {
                    $choices = $lang === 'en' ? ['True', 'False'] : ['صح', 'خطأ'];
                }
                $right = $this->rightChoices($answer, $choices);
                if ($right === []) {
                    $skipped[$line] = 'لم أفهم الإجابة الصحيحة: «'.$answer.'».';

                    continue;
                }
                foreach ($choices as $k => $text) {
                    $q['choices'][] = ['id' => null, 'body' => [$lang => $text], 'match' => [], 'is_correct' => in_array($k + 1, $right, true)];
                }
            } elseif ($type === 'short') {
                $q['settings'] = ['answers' => array_values(array_filter(array_map('trim', preg_split('/[|\n]/u', $answer)))), 'case_sensitive' => false];
            } elseif ($type === 'numeric') {
                [$value, $tol] = array_pad(preg_split('/\s*(±|\+-|\+\/-)\s*/u', $answer), 2, '0');
                $q['settings'] = ['answer' => trim($value), 'tolerance' => trim($tol)];
            }
            $questions[$line] = $q;
        }

        return ['questions' => $questions, 'skipped' => $skipped];
    }

    /**
     * Aiken: a question on one line, its choices «A. ...» or «A) ...» (Arabic letters too),
     * then «ANSWER: B» (or «الإجابة: ب»), a blank line between questions.
     */
    public function fromAiken(string $text, string $lang): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', str_replace("\r", '', $text));
        $questions = [];
        $skipped = [];
        $current = null;
        $startLine = 0;
        $lines = explode("\n", $text);
        foreach ($lines as $n => $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(ANSWER|الإجابة|الاجابة|الجواب)\s*[:：]\s*(.+)$/iu', $line, $m)) {
                if (! $current) {
                    continue;
                }
                $right = $this->rightChoices($m[2], array_column($current['options'], 'text'), $current['options']);
                if ($right === [] || count($current['options']) < 2) {
                    $skipped[$startLine] = 'سؤال بلا خيارات أو بإجابة غير مفهومة.';
                } else {
                    $questions[$startLine] = [
                        'id' => null, 'type' => count($right) > 1 ? 'multiple' : 'single', 'body' => [$lang => $current['body']],
                        'points' => 1, 'settings' => [], 'feedback' => [],
                        'choices' => array_map(fn ($o, $k) => ['id' => null, 'body' => [$lang => $o['text']], 'match' => [], 'is_correct' => in_array($k + 1, $right, true)],
                            $current['options'], array_keys($current['options'])),
                    ];
                }
                $current = null;

                continue;
            }
            if ($current && preg_match('/^([A-Za-z]|[أابجدهوزح]|هـ)\s*[\.\)\-]\s*(.+)$/u', $line, $m)) {
                $current['options'][] = ['label' => $m[1], 'text' => trim($m[2])];

                continue;
            }
            if ($current && $current['options'] === []) {
                $current['body'] .= "\n".$line;   // a question over several lines

                continue;
            }
            if ($current) {
                $skipped[$startLine] = 'سؤال بلا سطر ANSWER.';
            }
            $current = ['body' => $line, 'options' => []];
            $startLine = $n + 1;
        }
        if ($current) {
            $skipped[$startLine] = 'سؤال بلا سطر ANSWER.';
        }

        return ['questions' => $questions, 'skipped' => $skipped];
    }

    /** 1-based numbers of the right choices, from «B», «ب», «2», «1،3», or the choice's own text. */
    private function rightChoices(string $answer, array $choices, ?array $labelled = null): array
    {
        $answer = trim($answer);
        if ($answer === '') {
            return [];
        }
        $right = [];
        foreach (preg_split('/\s*[,،;؛]\s*|\s+و\s+/u', $answer) as $part) {
            $part = trim($part, " \t.)(");
            if ($part === '') {
                continue;
            }
            if ($labelled) {
                foreach ($labelled as $k => $o) {
                    if (mb_strtolower($o['label']) === mb_strtolower($part)) {
                        $right[] = $k + 1;
                    }
                }
            }
            $num = Grader::toNumber($part);
            if ($num !== null && $num >= 1 && $num <= count($choices) && floor($num) == $num) {
                $right[] = (int) $num;
            } elseif (preg_match('/^[A-Za-z]$/', $part)) {
                $right[] = ord(strtoupper($part)) - 64;
            } elseif (isset(self::LETTERS[$part])) {
                $right[] = self::LETTERS[$part];
            } else {
                foreach ($choices as $k => $text) {
                    if (Grader::normalize($text) === Grader::normalize($part)) {
                        $right[] = $k + 1;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($right, fn ($r) => $r >= 1 && $r <= count($choices))));
    }

    private function type(string $word, array $choices, string $answer): ?string
    {
        $w = Grader::normalize($word);
        if ($w !== '') {
            foreach (self::TYPE_WORDS as $type => $words) {
                foreach ($words as $candidate) {
                    if (Grader::normalize($candidate) === $w) {
                        return $type;
                    }
                }
            }
            if (in_array($word, Question::TYPES, true)) {
                return $word;
            }

            return null;
        }
        if ($choices !== []) {
            return preg_match('/[,،;؛]/u', $answer) ? 'multiple' : 'single';
        }

        return $answer === '' ? 'essay' : (Grader::toNumber(preg_split('/±|\+-/u', $answer)[0]) !== null ? 'numeric' : 'short');
    }

    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $col => $label) {
            $l = Grader::normalize((string) $label);
            if (preg_match('/^(الخيار|خيار|choice|option)\s*(\d+|[a-z]|[ابجدهوزح])?$/u', $l)) {
                $map['choices'][] = $col;
            } elseif (in_array($l, ['السوال', 'نص السوال', 'question', 'body', 'text'], true)) {
                $map['body'] ??= $col;
            } elseif (in_array($l, ['النوع', 'نوع السوال', 'type'], true)) {
                $map['type'] ??= $col;
            } elseif (in_array($l, ['الاجابه', 'الاجابه الصحيحه', 'الجواب', 'answer', 'correct'], true)) {
                $map['answer'] ??= $col;
            } elseif (in_array($l, ['الدرجه', 'درجه', 'points', 'marks', 'mark'], true)) {
                $map['points'] ??= $col;
            } elseif (in_array($l, ['التغذيه الراجعه', 'التغذيه', 'ملاحظه', 'feedback'], true)) {
                $map['feedback'] ??= $col;
            }
        }

        return $map;
    }

    // ---- GIFT (Moodle's text format) ----

    /** @return array{questions: array, skipped: array<int, string>} */
    public function fromGift(string $text, string $lang): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', str_replace("\r", '', $text));
        // Escaped specials become placeholders so they are not read as syntax.
        $esc = ['\\~' => "\u{E000}", '\\=' => "\u{E001}", '\\#' => "\u{E002}", '\\{' => "\u{E003}", '\\}' => "\u{E004}", '\\:' => "\u{E005}", '\\n' => "\n"];
        $text = strtr($text, $esc);
        $unescape = fn (string $t) => trim(strtr($t, ["\u{E000}" => '~', "\u{E001}" => '=', "\u{E002}" => '#', "\u{E003}" => '{', "\u{E004}" => '}', "\u{E005}" => ':']));

        $questions = [];
        $skipped = [];
        $line = 1;
        foreach (preg_split('/\n\s*\n/u', $text) as $block) {
            $start = $line;
            $line += substr_count($block, "\n") + 2;
            $block = trim(preg_replace('/^\s*\/\/.*$/mu', '', $block));
            if ($block === '' || str_starts_with($block, '$CATEGORY')) {
                continue;
            }
            $block = preg_replace('/^::.*?::/su', '', $block);   // the title
            if (! preg_match('/^(.*?)\{(.*)\}(.*)$/su', $block, $m)) {
                $questions[$start] = $this->question('description', $this->giftText($unescape($block)), $lang);

                continue;
            }
            $before = $this->giftText($unescape($m[1]));
            $after = $this->giftText($unescape($m[3]));
            $body = $after === '' ? $before : trim($before.' ____ '.$after);
            [$inside, $general] = array_pad(explode('####', $m[2], 2), 2, '');
            $q = $this->giftAnswers(trim($inside), $body, $lang, $unescape);
            if ($q === null) {
                $skipped[$start] = 'صيغة GIFT غير مفهومة.';

                continue;
            }
            if (trim($general) !== '') {
                $q['feedback'] = [$lang => $this->giftText($unescape($general))];
            }
            $questions[$start] = $q;
        }

        return ['questions' => $questions, 'skipped' => $skipped];
    }

    private function giftAnswers(string $inside, string $body, string $lang, callable $unescape): ?array
    {
        if ($inside === '') {
            return $this->question('essay', $body, $lang);
        }
        if (preg_match('/^(T|TRUE|F|FALSE)\b/u', $inside, $tf)) {
            $true = in_array($tf[1], ['T', 'TRUE'], true);

            return $this->question('true_false', $body, $lang, [
                ['body' => [$lang => $lang === 'ar' ? 'صح' : 'True'], 'is_correct' => $true],
                ['body' => [$lang => $lang === 'ar' ? 'خطأ' : 'False'], 'is_correct' => ! $true],
            ]);
        }
        if (str_starts_with($inside, '#')) {
            $value = trim(preg_replace('/#.*$/su', '', substr($inside, 1)));   // per-answer feedback dropped
            $value = ltrim($value, '=');
            if (preg_match('/^(-?[\d.]+)\.\.(-?[\d.]+)$/u', $value, $r)) {
                $answer = ((float) $r[1] + (float) $r[2]) / 2;
                $tolerance = abs((float) $r[2] - (float) $r[1]) / 2;
            } else {
                [$answer, $tolerance] = array_pad(explode(':', $value, 2), 2, '0');
            }
            if (Grader::toNumber($answer) === null) {
                return null;
            }

            return $this->question('numeric', $body, $lang, [], ['answer' => (string) $answer, 'tolerance' => (string) $tolerance]);
        }

        // Answers: =right ~wrong ~%50%partly; each may end in #feedback.
        preg_match_all('/([=~])\s*(%-?[\d.]+%)?((?:(?![=~]).)*)/su', $inside, $parts, PREG_SET_ORDER);
        $answers = [];
        foreach ($parts as $p) {
            $t = preg_replace('/#.*$/su', '', $p[3]);
            $weight = $p[2] !== '' ? (float) trim($p[2], '%') : ($p[1] === '=' ? 100 : 0);
            $answers[] = ['mark' => $p[1], 'weight' => $weight, 'text' => $unescape($t)];
        }
        $answers = array_values(array_filter($answers, fn ($a) => $a['text'] !== ''));
        if ($answers === []) {
            return null;
        }

        if (collect($answers)->every(fn ($a) => $a['mark'] === '=' && str_contains($a['text'], '->'))) {
            return $this->question('matching', $body, $lang, array_map(function ($a) use ($lang) {
                [$item, $match] = array_map('trim', explode('->', $a['text'], 2));

                return ['body' => [$lang => $item], 'match' => [$lang => $match]];
            }, $answers));
        }
        if (collect($answers)->every(fn ($a) => $a['mark'] === '=')) {
            return $this->question('short', $body, $lang, [], ['answers' => array_column($answers, 'text')]);
        }
        $right = collect($answers)->filter(fn ($a) => $a['weight'] > 0)->count();

        return $this->question($right > 1 ? 'multiple' : 'single', $body, $lang, array_map(fn ($a) => [
            'body' => [$lang => $a['text']], 'is_correct' => $a['weight'] > 0,
        ], $answers));
    }

    /** GIFT text: a format marker such as [html] dropped, HTML reduced to text. */
    private function giftText(string $t): string
    {
        $t = preg_replace('/^\[(html|moodle|plain|markdown)\]/iu', '', trim($t));

        return self::plain($t);
    }

    // ---- Moodle XML ----

    /** @return array{questions: array, skipped: array<int, string>} */
    public function fromMoodleXml(string $xml, string $lang): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previous);
        if ($doc === false || $doc->getName() !== 'quiz') {
            return ['questions' => [], 'skipped' => [1 => 'ليس ملف Moodle XML (يبدأ بـ <quiz>).']];
        }

        $questions = [];
        $skipped = [];
        $n = 0;
        foreach ($doc->question as $x) {
            $n++;
            $type = (string) $x['type'];
            if ($type === 'category') {
                continue;
            }
            $body = self::plain((string) $x->questiontext->text);
            $points = (float) ((string) $x->defaultgrade ?: 1);
            $answers = [];
            foreach ($x->answer as $a) {
                $answers[] = ['text' => self::plain((string) $a->text), 'fraction' => (float) $a['fraction'], 'tolerance' => (string) $a->tolerance];
            }

            $q = match ($type) {
                'multichoice' => $this->question(((string) $x->single) === 'false' ? 'multiple' : 'single', $body, $lang,
                    array_map(fn ($a) => ['body' => [$lang => $a['text']], 'is_correct' => $a['fraction'] > 0], $answers)),
                'truefalse' => $this->question('true_false', $body, $lang, [
                    ['body' => [$lang => $lang === 'ar' ? 'صح' : 'True'], 'is_correct' => collect($answers)->contains(fn ($a) => in_array(mb_strtolower($a['text']), ['true', 'صح'], true) && $a['fraction'] > 0)],
                    ['body' => [$lang => $lang === 'ar' ? 'خطأ' : 'False'], 'is_correct' => collect($answers)->contains(fn ($a) => in_array(mb_strtolower($a['text']), ['false', 'خطأ'], true) && $a['fraction'] > 0)],
                ]),
                'shortanswer' => $this->question('short', $body, $lang, [], [
                    'answers' => collect($answers)->where('fraction', '>=', 100)->pluck('text')->all(),
                    'case_sensitive' => ((string) $x->usecase) === '1',
                ]),
                'numerical' => ($best = collect($answers)->sortByDesc('fraction')->first())
                    ? $this->question('numeric', $body, $lang, [], ['answer' => $best['text'], 'tolerance' => $best['tolerance'] !== '' ? $best['tolerance'] : '0'])
                    : null,
                'matching' => $this->question('matching', $body, $lang, $this->moodlePairs($x, $lang)),
                'essay' => $this->question('essay', $body, $lang),
                'description' => $this->question('description', $body, $lang),
                default => null,
            };
            if ($q === null) {
                $skipped[$n] = 'نوع Moodle غير مدعوم: '.$type;

                continue;
            }
            $q['points'] = $type === 'description' ? 0 : $points;
            $feedback = self::plain((string) $x->generalfeedback->text);
            if ($feedback !== '') {
                $q['feedback'] = [$lang => $feedback];
            }
            $questions[$n] = $q;
        }

        return ['questions' => $questions, 'skipped' => $skipped];
    }

    /** A matching question's pairs (a SimpleXML list cannot go through collect(): its keys repeat). */
    private function moodlePairs(\SimpleXMLElement $x, string $lang): array
    {
        $pairs = [];
        foreach ($x->subquestion as $sq) {
            $item = self::plain((string) $sq->text);
            if ($item !== '') {
                $pairs[] = ['body' => [$lang => $item], 'match' => [$lang => self::plain((string) $sq->answer->text)]];
            }
        }

        return $pairs;
    }

    /** A question in the editor's shape, texts in one language. */
    private function question(string $type, string $body, string $lang, array $choices = [], array $settings = []): array
    {
        return [
            'id' => null, 'type' => $type, 'body' => [$lang => $body], 'points' => 1, 'settings' => $settings, 'feedback' => [],
            'choices' => array_map(fn ($c) => ['id' => null, 'body' => $c['body'], 'match' => $c['match'] ?? [], 'is_correct' => (bool) ($c['is_correct'] ?? false)], $choices),
        ];
    }

    /** HTML to plain text: paragraphs and line breaks kept as new lines. */
    public static function plain(string $html): string
    {
        $t = preg_replace('/<\s*(br|\/p|\/div|\/li)\b[^>]*>/iu', "\n", $html);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\n{3,}/u", "\n\n", $t)));
    }
}
