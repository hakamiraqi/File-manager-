<?php

namespace App\Services\Exams;

use App\Models\Exams\Answer;
use App\Models\Exams\Attempt;
use App\Models\Exams\Question;

/**
 * Marks an attempt. Each kind of question returns the points earned, or null when a
 * person has to mark it (essays). Responses, by type:
 *   single / true_false {choice: id}     multiple {choices: [id]}     short / essay {text}
 *   numeric {value}                      matching {pairs: {itemId: targetId}}     ordering {order: [id]}
 *   cloze {blanks: {0: 'typed text', 1: 'picked option'}}
 *   classify {pairs: {itemId: idOfAChoiceInTheCategory}}  (graded like matching)
 *   upload {file, name, size} (stored by ExamController::upload)   hotspot {x, y} in % of the picture
 */
class Grader
{
    public function grade(Attempt $attempt): Attempt
    {
        $questions = $attempt->orderedQuestions();
        $answers = $attempt->answers()->get()->keyBy('exam_question_id');
        $score = 0.0;
        $max = 0.0;
        $needsGrading = false;

        foreach ($questions as $question) {
            if (! $question->isGraded()) {
                continue;
            }
            $max += $question->points;
            $answer = $answers[$question->id] ?? null;

            if ($question->isManual()) {
                if ($answer && $answer->points !== null) {
                    $score += $answer->points;   // already marked by hand
                } elseif ($answer && Question::hasManualAnswer($answer->response)) {
                    $needsGrading = true;
                } elseif ($answer) {
                    $answer->update(['points' => 0]);
                }

                continue;
            }

            $points = round($this->fraction($question, $answer?->response ?? []) * $question->points, 2);
            if ($answer) {
                $answer->update(['points' => $points]);
            } else {
                Answer::create(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $question->id, 'response' => null, 'points' => 0]);
            }
            $score += $points;
        }

        $attempt->update(['score' => round($score, 2), 'max_score' => round($max, 2), 'needs_grading' => $needsGrading]);

        return $attempt;
    }

    /** Recomputes the total after a person marked an essay. */
    public function retotal(Attempt $attempt): Attempt
    {
        $answers = $attempt->answers()->with('question')->get();
        // Only questions still in the attempt count.
        $ids = $attempt->orderedQuestions()->pluck('id')->all();
        $answers = $answers->filter(fn (Answer $a) => in_array($a->exam_question_id, $ids, true));
        $pending = $answers->contains(fn (Answer $a) => $a->question?->isManual()
            && $a->points === null && Question::hasManualAnswer($a->response));
        $attempt->update(['score' => round((float) $answers->sum('points'), 2), 'needs_grading' => $pending]);

        return $attempt;
    }

    /** 0..1 of the question's points. */
    public function fraction(Question $question, ?array $response): float
    {
        $response ??= [];
        $choices = $question->choices;

        switch ($question->type) {
            case Question::SINGLE:
            case Question::TRUE_FALSE:
                $picked = (int) ($response['choice'] ?? 0);

                return $choices->firstWhere('id', $picked)?->is_correct ? 1.0 : 0.0;

            case Question::MULTIPLE:
                $picked = collect($response['choices'] ?? [])->map(fn ($id) => (int) $id)->unique();
                $correct = $choices->where('is_correct', true)->pluck('id');
                if ($correct->isEmpty()) {
                    return 0.0;
                }
                $right = $picked->intersect($correct)->count();
                $wrong = $picked->diff($correct)->count();

                return max(0.0, ($right - $wrong) / $correct->count());

            case Question::SHORT:
                $given = trim((string) ($response['text'] ?? ''));
                if ($given === '') {
                    return 0.0;
                }
                $caseSensitive = (bool) ($question->settings['case_sensitive'] ?? false);
                foreach ((array) ($question->settings['answers'] ?? []) as $accepted) {
                    if ($this->sameText($given, (string) $accepted, $caseSensitive)) {
                        return 1.0;
                    }
                }

                return 0.0;

            case Question::NUMERIC:
                $given = self::toNumber($response['value'] ?? null);
                $expected = self::toNumber($question->settings['answer'] ?? null);
                if ($given === null || $expected === null) {
                    return 0.0;
                }
                $tolerance = abs((float) (self::toNumber($question->settings['tolerance'] ?? 0) ?? 0));

                return abs($given - $expected) <= $tolerance + 1e-9 ? 1.0 : 0.0;

            case Question::MATCHING:
            case Question::CLASSIFY:
                $pairs = (array) ($response['pairs'] ?? []);
                if ($choices->isEmpty()) {
                    return 0.0;
                }
                $matchOf = $choices->mapWithKeys(fn ($c) => [$c->id => self::normalize(json_encode($c->match, JSON_UNESCAPED_UNICODE))]);
                $right = $choices->filter(fn ($c) => isset($pairs[$c->id], $matchOf[(int) $pairs[$c->id]])
                    && $matchOf[(int) $pairs[$c->id]] === $matchOf[$c->id])->count();

                return $right / $choices->count();

            case Question::CLOZE:
                $accepted = Cloze::acceptedByBlank($question);
                if ($accepted === []) {
                    return 0.0;
                }
                $given = (array) ($response['blanks'] ?? []);
                $right = 0;
                foreach ($accepted as $i => $answers) {
                    $value = trim((string) ($given[$i] ?? ''));
                    if ($value !== '' && collect($answers)->contains(fn ($a) => self::normalize($a) === self::normalize($value))) {
                        $right++;
                    }
                }

                return $right / count($accepted);

            case Question::HOTSPOT:
                $x = self::toNumber($response['x'] ?? null);
                $y = self::toNumber($response['y'] ?? null);
                if ($x === null || $y === null) {
                    return 0.0;
                }
                foreach ((array) ($question->settings['zones'] ?? []) as $z) {
                    if ($x >= $z['x'] && $x <= $z['x'] + $z['w'] && $y >= $z['y'] && $y <= $z['y'] + $z['h']) {
                        return 1.0;
                    }
                }

                return 0.0;

            case Question::ORDERING:
                $order = collect($response['order'] ?? [])->map(fn ($id) => (int) $id)->values();
                $correct = $choices->sortBy('position')->pluck('id')->values();
                if ($correct->isEmpty() || $order->count() !== $correct->count()) {
                    return 0.0;
                }
                $inPlace = $correct->filter(fn ($id, $i) => ($order[$i] ?? null) === $id)->count();

                return $inPlace / $correct->count();
        }

        return 0.0;
    }

    private function sameText(string $a, string $b, bool $caseSensitive): bool
    {
        if ($caseSensitive) {
            return preg_replace('/\s+/u', ' ', trim($a)) === preg_replace('/\s+/u', ' ', trim($b));
        }

        return self::normalize($a) === self::normalize($b);
    }

    /** Arabic spelling variants, diacritics, case and spacing ignored. */
    public static function normalize(?string $text): string
    {
        $t = mb_strtolower((string) $text);
        $t = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $t);
        $t = strtr($t, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي', 'ک' => 'ك', 'ی' => 'ي']);
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);

        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    /** "١٢٫٥", "12,5" and "12.5" are all 12.5. */
    public static function toNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        $v = strtr($v, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', ',' => '.', '٬' => '', ' ' => '', '−' => '-']);

        return is_numeric($v) ? (float) $v : null;
    }
}
