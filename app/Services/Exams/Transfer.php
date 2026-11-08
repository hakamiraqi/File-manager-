<?php

namespace App\Services\Exams;

use App\Http\Controllers\Exams\ManageController;
use App\Models\Exams\Answer;
use App\Models\Exams\Attempt;
use App\Models\Exams\BankCategory;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Moving exams between servers on a USB stick: an exam package (its settings, questions, the
 * bank questions its random slots draw from, and its pictures, sound and video) goes to a hall's
 * offline server; the results bundle (students, attempts, answers) comes back to the central one.
 * Both carry the exam's transfer key so the results find their exam.
 */
class Transfer
{
    private const EXAM_FIELDS = [
        'title', 'description', 'subject', 'stage', 'department', 'languages', 'direction', 'duration_minutes',
        'max_attempts', 'grading_method', 'pass_mark', 'shuffle_questions', 'shuffle_choices', 'show_results', 'show_answers',
        'is_practice', 'navigation', 'calculator', 'resume_code_required', 'anonymous_marking', 'appeals_open',
    ];

    private const QUESTION_FIELDS = ['type', 'format', 'body', 'feedback', 'points', 'settings', 'tags', 'difficulty'];

    public function exportExam(Exam $exam): array
    {
        if (! $exam->transfer_key) {
            $exam->update(['transfer_key' => (string) Str::uuid()]);
        }
        $media = [];
        $questions = $exam->questions()->with('choices')->get()->map(function (Question $q) use (&$media) {
            $row = $this->questionRow($q, $media);
            if ($q->type === Question::RANDOM) {
                // The slot's pool travels with it, in the bank's order.
                $row['pool'] = Question::with('choices')->where('bank_category_id', $q->settings['category_id'] ?? 0)->whereNull('exam_id')
                    ->orderBy('id')->get()->map(fn (Question $p) => $this->questionRow($p, $media))->all();
                $row['pool_name'] = BankCategory::whereKey($q->settings['category_id'] ?? 0)->value('name');
            }

            return $row;
        })->all();

        return [
            'format' => 'examnest-exam', 'version' => 1, 'transfer_key' => $exam->transfer_key, 'exported_at' => now()->toIso8601String(),
            'exam' => $exam->only(self::EXAM_FIELDS),
            'questions' => $questions,
            'media' => collect($media)->unique()->filter(fn ($n) => ManageController::imageExists($n))
                ->mapWithKeys(fn ($n) => [$n => base64_encode(Storage::disk('local')->get(ManageController::IMAGES.'/'.$n))])->all(),
        ];
    }

    /** A new exam (unpublished, owned by the importer) from a package; the same transfer key. */
    public function importExam(array $package, User $owner): Exam
    {
        if (($package['format'] ?? null) !== 'examnest-exam' || ! is_array($package['questions'] ?? null)) {
            throw new InvalidArgumentException('ليس ملف امتحان من هذا النظام.');
        }

        return DB::transaction(function () use ($package, $owner) {
            foreach ((array) ($package['media'] ?? []) as $name => $data) {
                if (preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,4}$/', (string) $name) && ($bytes = base64_decode((string) $data, true)) !== false
                    && hash('sha256', $bytes) === substr($name, 0, 64)) {
                    Storage::disk('local')->put(ManageController::IMAGES.'/'.$name, $bytes);
                }
            }
            $fields = array_intersect_key((array) ($package['exam'] ?? []), array_flip(self::EXAM_FIELDS));
            $exam = Exam::create($fields + ['user_id' => $owner->id, 'is_published' => false, 'transfer_key' => $package['transfer_key'] ?? (string) Str::uuid()]);

            foreach ($package['questions'] as $i => $row) {
                $settings = $row['settings'] ?? [];
                if (($row['type'] ?? null) === Question::RANDOM) {
                    $category = BankCategory::create(['user_id' => $owner->id, 'name' => ($row['pool_name'] ?? 'بنك').' — '.Question::pick($fields['title'] ?? [], null, []), 'is_shared' => false]);
                    foreach ((array) ($row['pool'] ?? []) as $p) {
                        $this->createQuestion($p, null, $category->id, 0);
                    }
                    $settings['category_id'] = $category->id;
                    $row['settings'] = $settings;
                }
                $this->createQuestion($row, $exam->id, null, $i);
            }

            return $exam;
        });
    }

    /** Every student's attempts and answers (files not included: they stay on the server they were sent to). */
    public function exportResults(Exam $exam): array
    {
        if (! $exam->transfer_key) {
            $exam->update(['transfer_key' => (string) Str::uuid()]);
        }
        [$byId] = $this->questionIndex($exam);
        $attempts = $exam->attempts()->with(['user', 'answers'])->orderBy('user_id')->orderBy('number')->get();

        return [
            'format' => 'examnest-results', 'version' => 1, 'transfer_key' => $exam->transfer_key, 'exported_at' => now()->toIso8601String(),
            'students' => $attempts->groupBy('user_id')->map(fn ($list) => [
                'name' => $list->first()->user?->name, 'email' => $list->first()->user?->email,
                'attempts' => $list->map(fn (Attempt $a) => [
                    'number' => $a->number, 'started_at' => $a->started_at?->toIso8601String(), 'deadline_at' => $a->deadline_at?->toIso8601String(),
                    'submitted_at' => $a->submitted_at?->toIso8601String(),
                    'order' => collect($a->question_order ?? [])->map(fn ($id) => $byId[$id] ?? null)->filter()->values()->all(),
                    'drawn' => collect($a->drawn ?? [])->map(fn ($points, $id) => ['ref' => $byId[$id] ?? null, 'points' => $points])->filter(fn ($d) => $d['ref'])->values()->all(),
                    'answers' => $a->answers->filter(fn (Answer $ans) => isset($byId[$ans->exam_question_id]))->map(fn (Answer $ans) => [
                        'ref' => $byId[$ans->exam_question_id], 'response' => isset($ans->response['file']) ? ['name' => $ans->response['name'] ?? null] : $ans->response,
                        'points' => $ans->points, 'comment' => $ans->comment, 'flagged' => $ans->flagged,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** Results from another server into the same exam: missing students get accounts; attempts already here are kept. */
    public function importResults(Exam $exam, array $bundle): array
    {
        if (($bundle['format'] ?? null) !== 'examnest-results') {
            throw new InvalidArgumentException('ليس ملف نتائج من هذا النظام.');
        }
        if (! $exam->transfer_key || ! hash_equals($exam->transfer_key, (string) ($bundle['transfer_key'] ?? ''))) {
            throw new InvalidArgumentException('النتائج لامتحان آخر (مفتاح النقل لا يطابق).');
        }
        [, $byRef] = $this->questionIndex($exam);
        $added = 0;
        $accounts = 0;
        DB::transaction(function () use ($bundle, $exam, $byRef, &$added, &$accounts) {
            foreach ((array) ($bundle['students'] ?? []) as $s) {
                $email = mb_strtolower(trim((string) ($s['email'] ?? '')));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $user = User::where('email', $email)->first();
                if (! $user) {
                    $user = User::create(['name' => (string) ($s['name'] ?? $email), 'email' => $email, 'password' => Hash::make(Str::random(32)),
                        'role' => 'user', 'is_active' => true, 'email_verified_at' => now()]);
                    $accounts++;
                }
                foreach ((array) ($s['attempts'] ?? []) as $a) {
                    if (Attempt::where('exam_id', $exam->id)->where('user_id', $user->id)->where('number', (int) $a['number'])->exists()) {
                        continue;
                    }
                    $attempt = Attempt::create([
                        'exam_id' => $exam->id, 'user_id' => $user->id, 'number' => (int) $a['number'],
                        'started_at' => $a['started_at'] ?? now(), 'deadline_at' => $a['deadline_at'] ?? null, 'submitted_at' => $a['submitted_at'] ?? null,
                        'question_order' => collect($a['order'] ?? [])->map(fn ($r) => $byRef[$r] ?? null)->filter()->values()->all(),
                        'drawn' => collect($a['drawn'] ?? [])->mapWithKeys(fn ($d) => isset($byRef[$d['ref']]) ? [$byRef[$d['ref']] => $d['points']] : [])->all() ?: null,
                    ]);
                    foreach ((array) ($a['answers'] ?? []) as $ans) {
                        if (isset($byRef[$ans['ref'] ?? ''])) {
                            Answer::create(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $byRef[$ans['ref']], 'response' => $ans['response'] ?? null,
                                'points' => $ans['points'] ?? null, 'comment' => $ans['comment'] ?? null, 'flagged' => (bool) ($ans['flagged'] ?? false)]);
                        }
                    }
                    if ($attempt->submitted_at) {
                        app(Grader::class)->grade($attempt);   // hand marks travel with the answers and are kept
                    }
                    $added++;
                }
            }
        });

        return ['attempts' => $added, 'accounts' => $accounts];
    }

    /** Questions by a reference both servers agree on: the exam's own by position (q3), a slot's pool by slot and order (s2.5). */
    private function questionIndex(Exam $exam): array
    {
        $byId = [];
        foreach ($exam->questions()->get() as $i => $q) {
            if ($q->type === Question::RANDOM) {
                foreach (Question::where('bank_category_id', $q->settings['category_id'] ?? 0)->whereNull('exam_id')->orderBy('id')->pluck('id') as $k => $pid) {
                    $byId[$pid] ??= "s{$i}.{$k}";
                }
            } else {
                $byId[$q->id] = "q{$i}";
            }
        }

        return [$byId, array_flip($byId)];
    }

    private function questionRow(Question $q, array &$media): array
    {
        $row = $q->only(self::QUESTION_FIELDS);
        if (! empty($q->settings['image'])) {
            $media[] = $q->settings['image'];
        }
        foreach ((array) $q->body + (array) $q->feedback as $text) {
            preg_match_all('#/exams/images/([a-f0-9]{64}\.[a-z0-9]{2,4})#', (string) $text, $m);
            array_push($media, ...$m[1]);
        }
        $row['choices'] = $q->choices->map(fn (Choice $c) => ['body' => $c->body, 'match' => $c->match, 'is_correct' => $c->is_correct])->values()->all();

        return $row;
    }

    private function createQuestion(array $row, ?int $examId, ?int $categoryId, int $position): Question
    {
        $type = in_array($row['type'] ?? null, Question::TYPES, true) ? $row['type'] : Question::DESCRIPTION;
        $q = Question::create(['type' => $type, 'exam_id' => $examId, 'bank_category_id' => $categoryId, 'position' => $position]
            + array_intersect_key($row, array_flip(self::QUESTION_FIELDS)));
        if ($q->format === 'html') {
            $q->update(['body' => array_map(fn ($t) => HtmlCleaner::clean((string) $t), (array) $q->body)]);
        }
        foreach ((array) ($row['choices'] ?? []) as $j => $c) {
            Choice::create(['exam_question_id' => $q->id, 'body' => $c['body'] ?? [], 'match' => $c['match'] ?? null, 'is_correct' => (bool) ($c['is_correct'] ?? false), 'position' => $j]);
        }

        return $q;
    }
}
