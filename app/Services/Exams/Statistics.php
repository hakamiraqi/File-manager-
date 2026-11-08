<?php

namespace App\Services\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Question;
use Illuminate\Support\Collection;

/**
 * Classical item analysis over each student's first submitted attempt (later attempts would
 * count the students who repeat twice):
 *   facility       the average share of a question's points the students earned (0–100)
 *   discrimination the correlation of a question's score with the rest of the exam (−1..1):
 *                  above 0.3 good, 0.2–0.3 fair, below 0.2 weak, negative = check the key
 *   alpha          Cronbach's alpha, how consistently the questions measure the same thing
 * Questions drawn at random are left out: not every student had them.
 */
class Statistics
{
    /**
     * @param  Collection<int, Question>  $questions  the exam's own graded questions
     * @param  Collection<int, Attempt>  $attempts  with answers loaded
     */
    public function analyse(Collection $questions, Collection $attempts): array
    {
        $firsts = $attempts->filter(fn (Attempt $a) => $a->submitted_at !== null && $a->score !== null)
            ->sortBy('number')->unique('user_id')->values();

        // scores[attempt][question] = points earned
        $scores = $firsts->map(function (Attempt $a) use ($questions) {
            $byQuestion = $a->answers->keyBy('exam_question_id');

            return $questions->mapWithKeys(fn (Question $q) => [$q->id => (float) ($byQuestion[$q->id]->points ?? 0)])->all();
        });
        $totals = $scores->map(fn ($row) => array_sum($row));
        $percents = $firsts->map(fn (Attempt $a) => $a->percent())->filter(fn ($p) => $p !== null)->values();

        $items = $questions->mapWithKeys(function (Question $q) use ($scores, $totals) {
            $item = $scores->pluck($q->id);
            $rest = $totals->map(fn ($t, $i) => $t - $item[$i]);

            return [$q->id => [
                'facility' => $item->isEmpty() || $q->points <= 0 ? null : round($item->avg() / $q->points * 100),
                'discrimination' => $item->count() < 3 ? null : self::correlation($item->all(), $rest->all()),
            ]];
        });

        return [
            'students' => $firsts->count(),
            'mean' => $percents->isEmpty() ? null : round($percents->avg(), 1),
            'median' => $percents->isEmpty() ? null : round($percents->median(), 1),
            'sd' => $percents->count() < 2 ? null : round(sqrt(self::variance($percents->all())), 1),
            'min' => $percents->min(),
            'max' => $percents->max(),
            'alpha' => $this->alpha($questions, $scores, $totals),
            // How many students in each tenth: 0–9 %, 10–19 %, …, 90–100 %.
            'distribution' => collect(range(0, 9))->map(fn ($b) => $percents->filter(fn ($p) => min(9, (int) floor($p / 10)) === $b)->count())->all(),
            'items' => $items->all(),
        ];
    }

    private function alpha(Collection $questions, Collection $scores, Collection $totals): ?float
    {
        $k = $questions->count();
        if ($k < 2 || $scores->count() < 3) {
            return null;
        }
        $totalVariance = self::variance($totals->all());
        if ($totalVariance <= 0) {
            return null;
        }
        $itemVariance = $questions->sum(fn (Question $q) => self::variance($scores->pluck($q->id)->all()));

        return round($k / ($k - 1) * (1 - $itemVariance / $totalVariance), 2);
    }

    /** Sample variance. */
    public static function variance(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;

        return array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1);
    }

    /** Pearson correlation; null when either side does not vary. */
    public static function correlation(array $x, array $y): ?float
    {
        $x = array_values($x);
        $y = array_values($y);
        $n = count($x);
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $sxy = $sxx = $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sxy += ($x[$i] - $mx) * ($y[$i] - $my);
            $sxx += ($x[$i] - $mx) ** 2;
            $syy += ($y[$i] - $my) ** 2;
        }
        if ($sxx <= 1e-12 || $syy <= 1e-12) {
            return null;
        }

        return round($sxy / sqrt($sxx * $syy), 2);
    }
}
