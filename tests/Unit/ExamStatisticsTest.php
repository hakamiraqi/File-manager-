<?php

namespace Tests\Unit;

use App\Models\Exams\Answer;
use App\Models\Exams\Attempt;
use App\Models\Exams\Question;
use App\Services\Exams\Statistics;
use Tests\TestCase;

class ExamStatisticsTest extends TestCase
{
    private function attempt(int $user, int $number, array $points, bool $submitted = true): Attempt
    {
        $a = new Attempt;
        $a->forceFill(['user_id' => $user, 'number' => $number, 'submitted_at' => $submitted ? now() : null,
            'score' => array_sum($points), 'max_score' => 2]);
        $a->setRelation('answers', collect($points)->map(fn ($p, $qid) => (new Answer)->forceFill(['exam_question_id' => $qid, 'points' => $p]))->values());

        return $a;
    }

    public function test_item_analysis_of_a_small_class(): void
    {
        $questions = collect([1, 2])->map(fn ($id) => (new Question)->forceFill(['id' => $id, 'points' => 1]));
        $attempts = collect([
            $this->attempt(1, 1, [1 => 1, 2 => 1]),
            $this->attempt(2, 1, [1 => 1, 2 => 0]),
            $this->attempt(3, 1, [1 => 0, 2 => 0]),
            $this->attempt(4, 1, [1 => 1, 2 => 1]),
            $this->attempt(3, 2, [1 => 1, 2 => 1]),           // a second try: left out
            $this->attempt(5, 1, [1 => 1, 2 => 1], false),    // not submitted: left out
        ]);

        $s = (new Statistics)->analyse($questions, $attempts);

        $this->assertSame(4, $s['students']);
        $this->assertEquals(62.5, $s['mean']);
        $this->assertEquals(75.0, $s['median']);
        $this->assertSame([1, 0, 0, 0, 0, 1, 0, 0, 0, 2], $s['distribution']);
        $this->assertEquals(0.73, $s['alpha']);
        $this->assertEquals(75.0, $s['items'][1]['facility']);
        $this->assertEquals(0.58, $s['items'][1]['discrimination']);
        $this->assertEquals(0.58, $s['items'][2]['discrimination']);
    }

    public function test_too_few_students_give_no_correlations(): void
    {
        $questions = collect([(new Question)->forceFill(['id' => 1, 'points' => 1]), (new Question)->forceFill(['id' => 2, 'points' => 1])]);
        $s = (new Statistics)->analyse($questions, collect([$this->attempt(1, 1, [1 => 1, 2 => 0])]));

        $this->assertNull($s['alpha']);
        $this->assertNull($s['items'][1]['discrimination']);
        $this->assertNull($s['sd']);
        $this->assertNull(Statistics::correlation([1, 1, 1], [0, 1, 2]));
    }
}
