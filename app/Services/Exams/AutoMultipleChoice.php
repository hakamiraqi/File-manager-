<?php

namespace App\Services\Exams;

use App\Models\Exams\Answer;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Auto Multiple Choice (AMC, free software for paper MCQ exams): the exam goes out as an AMC
 * project (an AMC-TXT source and the students list, so AMC prints a named sheet for each
 * student), and AMC's results CSV comes back in as each student's marked attempt.
 *
 * The CSV is best exported from AMC with «ticked boxes» on (letters): the answers are then
 * marked here like any attempt. Without them, AMC's own per-question scores are taken.
 */
class AutoMultipleChoice
{
    public const TYPES = [Question::SINGLE, Question::MULTIPLE, Question::TRUE_FALSE];

    public function __construct(private Grader $grader) {}

    /** The student's key in the list and on AMC's results: S and the account number. */
    public static function key(int $userId): string
    {
        return 'S'.$userId;
    }

    public function questions(Exam $exam)
    {
        return $exam->questions()->with('choices')->get()->filter(fn (Question $q) => in_array($q->type, self::TYPES, true) && $q->choices->isNotEmpty())->values();
    }

    /** The AMC-TXT source. */
    public function source(Exam $exam): string
    {
        $lang = $exam->languages[0] ?? 'ar';
        $locale = $lang;
        $arabic = $lang === 'ar';
        // Arabic sheets: the text goes in as LaTeX, so Latin words, numbers and symbols can be set
        // left to right in a Latin font (the Arabic font has no Latin letters, and turns digits).
        $t = fn (string $s) => $arabic ? self::arabicTex(self::line($s)) : self::line($s);
        $text = fn ($v) => Question::pick($v, $locale, $exam->languages ?? []);
        $out = [
            '# '.config('app.name').' — '.$exam->text('title').' ('.now()->toDateString().')',
            'PaperSize: A4',
        ];
        if ($arabic) {
            array_push($out, 'Lang: AR', 'ArabicFont: Amiri', 'Font: Linux Libertine O', 'LaTeX: 1');
        }
        $out[] = 'Title: '.$t($exam->text('title'));
        if (filled($exam->text('description'))) {
            $out[] = 'Presentation: '.$t(HtmlCleaner::text($exam->text('description')));
        }
        $out[] = 'ShuffleQuestions: '.($exam->shuffle_questions ? 1 : 0);
        $out[] = 'PreAssociation: students.csv';
        $out[] = 'PreAssociationKey: id';
        $out[] = 'PreAssociationName: \\name{}';
        $out[] = 'SeparateAnswerSheet: 1';
        $out[] = '';
        foreach ($this->questions($exam) as $q) {
            $marker = $q->type === Question::MULTIPLE ? '**' : '*';
            $opts = 'id=q'.$q->id.($exam->shuffle_choices && $q->type !== Question::TRUE_FALSE ? '' : ',ordered');
            $points = rtrim(rtrim(number_format((float) $q->points, 2, '.', ''), '0'), '.') ?: '0';
            $scoring = $q->type === Question::MULTIPLE ? '{haut='.$points.'}' : '{b='.$points.'}';
            $out[] = $marker.'['.$opts.']'.$scoring.' '.$t(HtmlCleaner::text($text($q->body)));
            foreach ($q->choices as $c) {
                $out[] = ($c->is_correct ? '+ ' : '- ').$t(HtmlCleaner::text($text($c->body)));
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /** The students list AMC prints the sheets from. */
    public function students(Exam $exam): string
    {
        $rows = ['id,name'];
        foreach ($exam->roster() as $u) {
            // AMC wants no underscores in names.
            $rows[] = self::key($u->id).','.self::csv(str_replace('_', ' ', $u->name));
        }

        return implode("\n", $rows)."\n";
    }

    /**
     * AMC's results CSV into attempts: [rows read, attempts made, how (ticked|scores|mark), unknown keys].
     */
    public function import(Exam $exam, string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\R/', trim($csv));
        if (count($lines) < 2) {
            throw new InvalidArgumentException('الملف فارغ.');
        }
        $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',');
        $header = array_map('trim', str_getcsv($lines[0], $sep));
        $rows = array_map(fn ($l) => str_getcsv($l, $sep), array_slice($lines, 1));
        $roster = $exam->roster()->keyBy(fn (User $u) => self::key($u->id));

        // The key column: the one whose values are our students' keys.
        $keyCol = collect($header)->keys()->sortByDesc(fn ($i) => collect($rows)->filter(fn ($r) => isset($roster[trim($r[$i] ?? '')]))->count())->first();
        if ($keyCol === null || ! collect($rows)->contains(fn ($r) => isset($roster[trim($r[$keyCol] ?? '')]))) {
            throw new InvalidArgumentException('لا عمود فيه مفاتيح طلاب هذا الامتحان (S ورقم). صدّر النتائج من مشروع AMC الذي أُنشئ من هذا الامتحان.');
        }
        $questions = $this->questions($exam)->keyBy('id');
        $col = fn (string $prefix, int $qid) => collect($header)->search(fn ($h) => strcasecmp($h, $prefix.'q'.$qid) === 0);
        $ticked = $questions->mapWithKeys(fn ($q) => [$q->id => ($i = $col('TICKED:', $q->id)) !== false ? $i : $col('CHECKED:', $q->id)])->filter(fn ($i) => $i !== false);
        $scores = $questions->mapWithKeys(fn ($q) => [$q->id => $col('', $q->id)])->filter(fn ($i) => $i !== false);
        $markCol = collect($header)->search(fn ($h) => in_array(mb_strtolower($h), ['mark', 'note', 'marks', 'total', 'score', 'الدرجة', 'العلامة'], true));
        $how = $ticked->isNotEmpty() ? 'ticked' : ($scores->isNotEmpty() ? 'scores' : ($markCol !== false ? 'mark' : null));
        if (! $how) {
            throw new InvalidArgumentException('لا أعمدة للإجابات ولا للدرجات في الملف.');
        }

        $made = 0;
        $unknown = [];
        DB::transaction(function () use ($exam, $rows, $keyCol, $roster, $questions, $ticked, $scores, $markCol, $how, &$made, &$unknown) {
            foreach ($rows as $r) {
                $key = trim($r[$keyCol] ?? '');
                if ($key === '') {
                    continue;
                }
                $user = $roster[$key] ?? null;
                if (! $user) {
                    $unknown[] = $key;

                    continue;
                }
                $attempt = Attempt::firstOrNew(['exam_id' => $exam->id, 'user_id' => $user->id, 'number' => 1]);
                $attempt->fill(['started_at' => $attempt->started_at ?? now(), 'submitted_at' => now(), 'question_order' => $questions->keys()->all(), 'ip' => 'amc'])->save();
                foreach ($questions as $qid => $q) {
                    if ($how === 'ticked') {
                        $picked = self::boxes((string) ($r[$ticked[$qid] ?? -1] ?? ''), $q->choices->count());
                        $ids = collect($picked)->map(fn ($i) => $q->choices[$i]->id)->values();
                        $response = $ids->isEmpty() ? null : ($q->type === Question::MULTIPLE ? ['choices' => $ids->all()] : ['choice' => $ids->first()]);
                        Answer::updateOrCreate(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $qid], ['response' => $response]);
                        \App\Models\Exams\AnswerLog::record($attempt, $qid, $response, 'amc', null);
                    } elseif ($how === 'scores' && isset($scores[$qid])) {
                        $points = min((float) $q->points, max(0, (float) str_replace(',', '.', (string) ($r[$scores[$qid]] ?? 0))));
                        Answer::updateOrCreate(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $qid], ['points' => $points]);
                    }
                }
                if ($how === 'ticked') {
                    $this->grader->grade($attempt);
                } elseif ($how === 'scores') {
                    $attempt->update(['max_score' => $questions->sum('points')]);
                    $this->grader->retotal($attempt);
                } else {
                    $attempt->update(['score' => (float) str_replace(',', '.', (string) ($r[$markCol] ?? 0)), 'max_score' => $questions->sum('points'), 'needs_grading' => false]);
                }
                $made++;
            }
        });

        return ['rows' => count($rows), 'attempts' => $made, 'how' => $how, 'unknown' => array_slice(array_unique($unknown), 0, 20)];
    }

    /** Ticked boxes as AMC writes them: letters (AC), or 0/1 per box (0;1;0;0), into 0-based positions. */
    public static function boxes(string $value, int $count): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        if (preg_match('/^[01](?:[;:,\s]?[01])*$/', $value)) {
            $bits = preg_split('/[;:,\s]?/', $value, -1, PREG_SPLIT_NO_EMPTY);

            return array_values(array_filter(array_keys($bits), fn ($i) => $bits[$i] === '1' && $i < $count));
        }
        $out = [];
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z]/', '', $value))) as $ch) {
            $i = ord($ch) - 65;
            if ($i >= 0 && $i < $count) {
                $out[] = $i;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Arabic text as LaTeX for an Arabic AMC sheet: LaTeX's special characters escaped, and every
     * run of Latin letters, digits and symbols (H2O, HTTPS, 15%, π ≈ 3.14, 2⁵) set left to right in
     * the Latin font, so it reads as typed.
     */
    public static function arabicTex(string $s): string
    {
        $esc = fn (string $x) => strtr($x, ['\\' => '\\textbackslash{}', '{' => '\\{', '}' => '\\}', '%' => '\\%', '$' => '\\$', '#' => '\\#',
            '&' => '\\&', '_' => '\\_', '^' => '\\^{}', '~' => '\\~{}']);
        $sup = ['⁰' => '0', '¹' => '1', '²' => '2', '³' => '3', '⁴' => '4', '⁵' => '5', '⁶' => '6', '⁷' => '7', '⁸' => '8', '⁹' => '9'];
        $sub = ['₀' => '0', '₁' => '1', '₂' => '2', '₃' => '3', '₄' => '4', '₅' => '5', '₆' => '6', '₇' => '7', '₈' => '8', '₉' => '9'];
        $latin = function (string $run) use ($esc, $sup, $sub): string {
            $run = $esc($run);
            $run = preg_replace_callback('/[⁰¹²³⁴⁵⁶⁷⁸⁹]+/u', fn ($m) => '\\textsuperscript{'.strtr($m[0], $sup).'}', $run);
            $run = preg_replace_callback('/[₀₁₂₃₄₅₆₇₈₉]+/u', fn ($m) => '\\textsubscript{'.strtr($m[0], $sub).'}', $run);

            return '\\LR{\\rmfamily '.$run.'}';
        };
        // A run starts with a Latin letter, a digit or a maths sign, and goes on over anything that
        // is not Arabic, ending before trailing spaces and Arabic punctuation.
        $out = '';
        $pos = 0;
        preg_match_all('/[A-Za-z0-9π≈×÷±√∞°⁰¹²³⁴⁵⁶⁷⁸⁹₀₁₂₃₄₅₆₇₈₉][^\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]*/u', $s, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$run, $at]) {
            $trimmed = rtrim($run, " \t(،؛؟");
            $out .= $esc(substr($s, $pos, $at - $pos)).$latin($trimmed);
            $pos = $at + strlen($trimmed);
        }

        return $out.$esc(substr($s, $pos));
    }

    /** One line of text for AMC-TXT (a line break inside would start something else). */
    private static function line(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function csv(string $s): string
    {
        return preg_match('/[",\n]/', $s) ? '"'.str_replace('"', '""', $s).'"' : $s;
    }
}
