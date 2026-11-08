<?php

namespace App\Services\Exams;

use App\Models\Exams\Question;

/**
 * Turns an Excel/CSV sheet or an Aiken text file into questions in the editor's shape
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
}
