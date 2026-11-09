<?php

namespace App\Console\Commands;

use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Group;
use App\Models\Exams\Question;
use App\Models\User;
use App\Services\Exams\AmcConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * A 100-question paper exam for Auto Multiple Choice (70 single answer, 20 true/false, 10 several
 * answers; general science, maths, geography and computing), given to a group of the students
 * there are, with its AMC sheets built on the AMC server when one is set.
 */
class CreateAmcDemoExam extends Command
{
    protected $signature = 'exams:amc-demo {--fresh : replace an earlier one} {--rebuild : build the earlier one\'s AMC sheets again}';

    protected $description = 'Make a 100-question exam for Auto Multiple Choice and build its sheets.';

    public const TITLE = 'امتحان AMC تجريبي — 100 سؤال';

    public function handle(AmcConnector $amc): int
    {
        $owner = User::where('role', 'admin')->orderByDesc('is_master_admin')->orderBy('id')->first();
        if (! $owner) {
            $this->error('No admin account.');

            return self::FAILURE;
        }
        if ($old = Exam::where('title->ar', self::TITLE)->first()) {
            if ($this->option('rebuild')) {
                $r = $amc->build($old);
                $this->info("Exam {$old->id}: AMC sheets built again, ".($r['pages'] ?? '?').' pages.');

                return self::SUCCESS;
            }
            if (! $this->option('fresh')) {
                $this->warn("Already there (exam {$old->id}); use --fresh to replace it.");

                return self::SUCCESS;
            }
            $old->delete();
        }
        $items = json_decode((string) file_get_contents(database_path('data/amc-demo-questions.json')), true);

        $exam = DB::transaction(function () use ($owner, $items) {
            $exam = Exam::create([
                'user_id' => $owner->id, 'title' => ['ar' => self::TITLE], 'languages' => ['ar'], 'direction' => 'auto',
                'description' => ['ar' => 'امتحان ورقي بأوراق الفقاعات: 70 سؤالاً بإجابة واحدة، و20 صح/خطأ، و10 بعدة إجابات. ظلّل الخانة كاملة بقلم غامق.'],
                'subject' => 'ثقافة علمية عامة', 'max_attempts' => 1, 'grading_method' => 'highest', 'show_results' => 'after_submit',
                'show_answers' => true, 'is_published' => true, 'pass_mark' => 50, 'shuffle_questions' => false, 'shuffle_choices' => false,
            ]);
            foreach ($items as $i => $it) {
                $q = Question::create(['exam_id' => $exam->id, 'type' => $it['type'], 'format' => 'text', 'body' => ['ar' => $it['body']],
                    'points' => 1, 'position' => $i]);
                foreach ($it['choices'] as $j => $c) {
                    Choice::create(['exam_question_id' => $q->id, 'body' => ['ar' => $c], 'is_correct' => in_array($j, $it['right'], true), 'position' => $j]);
                }
            }
            // The students there are (not staff), as one group given the exam.
            $students = User::where('role', '!=', 'admin')->where('is_examiner', false)->where('is_invigilator', false)->pluck('id');
            $group = Group::firstOrCreate(['name' => 'مجموعة تجربة AMC'], ['user_id' => $owner->id]);
            $group->members()->syncWithoutDetaching($students);
            $exam->groups()->sync([$group->id]);

            return $exam;
        });
        $this->info("Exam {$exam->id}: ".$exam->questions()->count().' questions, '.$exam->roster()->count().' students.');

        if (! AmcConnector::configured()) {
            $this->warn('No AMC server set: build the sheets from the exam\'s bubble-sheet page.');

            return self::SUCCESS;
        }
        try {
            $r = $amc->build($exam);
            $this->info('AMC sheets built: '.($r['pages'] ?? '?').' pages.');
        } catch (\Throwable $e) {
            $this->error('AMC: '.mb_substr($e->getMessage(), 0, 1500));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
