<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Answer;
use App\Models\Exams\Attempt;
use App\Models\Exams\BankCategory;
use App\Models\Exams\Choice;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MoreTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = User::factory()->create(['is_examiner' => true]);
    }

    private function exam(array $questions, array $settings = []): Exam
    {
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), $settings + [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'show_answers' => true, 'is_published' => true,
            'questions' => $questions,
        ])->assertSessionHasNoErrors();

        return $exam->fresh();
    }

    public function test_drag_words_and_labels_and_recordings_and_drawings(): void
    {
        $image = $this->actingAs($this->teacher)->post(route('exams.manage.images.store'), ['image' => UploadedFile::fake()->image('body.png', 400, 400)])->json('image');
        $exam = $this->exam([
            ['type' => 'drag_text', 'points' => 2, 'body' => ['ar' => 'عاصمة العراق [[بغداد]] وعاصمة مصر [[القاهرة]]'], 'settings' => ['distractors' => ['دمشق', 'بغداد']]],
            ['type' => 'drag_image', 'points' => 2, 'body' => ['ar' => 'ضع الأسماء'], 'settings' => ['image' => $image, 'zones' => [['x' => 0, 'y' => 0, 'w' => 50, 'h' => 50], ['x' => 50, 'y' => 50, 'w' => 50, 'h' => 50]]],
                'choices' => [['body' => ['ar' => 'القلب']], ['body' => ['ar' => 'الرئة']]]],
            ['type' => 'audio', 'points' => 3, 'body' => ['ar' => 'اقرأ النص بصوت'], 'settings' => ['max_seconds' => 30]],
            ['type' => 'drawing', 'points' => 3, 'body' => ['ar' => 'ارسم خلية']],
        ]);
        [$drag, $labels, $audio, $drawing] = $exam->questions()->with('choices')->get()->all();
        $this->assertSame(['distractors' => ['دمشق', 'بغداد']], $drag->settings);
        $this->assertSame(['max_seconds' => 30], $audio->settings);

        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('questions.0.body', '')->has('questions.0.words', 3)->where('questions.0.parts.1', ['blank' => 0, 'kind' => 'drag'])
            ->has('questions.1.labels', 2)->missing('questions.1.zones')->where('questions.1.choices', [])
            ->where('questions.2.max_seconds', 30));

        // One word right of two; one label in its place of two.
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $drag->id, 'response' => ['blanks' => ['بغداد', 'دمشق']]]);
        [$heart, $lung] = $labels->choices->all();
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $labels->id, 'response' => ['places' => [
            $heart->id => ['x' => 20, 'y' => 30], $lung->id => ['x' => 10, 'y' => 10],
        ]]]);
        // A recording and a drawing come as files of their own kind.
        $send = fn (Question $q, UploadedFile $f) => $this->actingAs($student)->post(route('exams.attempts.upload', $attempt), ['question_id' => $q->id, 'file' => $f]);
        $send($audio, UploadedFile::fake()->create('answer.webm', 50, 'audio/webm'))->assertOk();
        $send($drawing, UploadedFile::fake()->create('drawing.jpg', 50, 'image/jpeg'))->assertSessionHasErrors('file');
        $send($drawing, UploadedFile::fake()->image('drawing.png'))->assertOk();
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $audio->id, 'response' => ['file' => '../x']])->assertOk();
        $this->assertSame('answer.webm', Answer::where('exam_question_id', $audio->id)->first()->response['name']);

        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt));
        $attempt->refresh();
        $this->assertSame(2.0, $attempt->score);   // 1 + 1
        $this->assertTrue($attempt->needs_grading);
        $this->actingAs($this->teacher)->get(route('exams.manage.results', $exam))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('attempts.0.essays', 2)->where('attempts.0.essays.0.file.name', 'answer.webm'));
        $this->actingAs($student)->get(route('exams.attempts.result', $attempt))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('review.1.marks', 2)->where('review.1.marks.0.zone.w', fn ($w) => $w == 50));
    }

    public function test_a_label_without_a_place_is_refused(): void
    {
        $image = $this->actingAs($this->teacher)->post(route('exams.manage.images.store'), ['image' => UploadedFile::fake()->image('a.png')])->json('image');
        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit',
            'questions' => [['type' => 'drag_image', 'body' => ['ar' => 'ضع'], 'settings' => ['image' => $image, 'zones' => [['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]]],
                'choices' => [['body' => ['ar' => 'أ']], ['body' => ['ar' => 'ب']]]]],
        ])->assertSessionHasErrors('questions.0.settings');
    }

    public function test_no_going_back_in_a_sequential_exam(): void
    {
        $short = fn ($n) => ['type' => 'short', 'points' => 1, 'body' => ['ar' => "سؤال {$n}"], 'settings' => ['answers' => ['x']]];
        $exam = $this->exam([$short(1), $short(2), $short(3)], ['navigation' => 'sequential', 'calculator' => 'scientific']);
        $this->assertSame('sequential', $exam->navigation);
        [$q1, $q2] = $exam->questions()->get()->all();
        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $attempt = Attempt::firstOrFail();

        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $q1->id, 'response' => ['text' => 'x']])->assertOk();
        $this->actingAs($student)->postJson(route('exams.attempts.advance', $attempt), ['to' => 1])->assertJson(['position' => 1]);
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $q1->id, 'response' => ['text' => 'y']])->assertStatus(422);
        $this->actingAs($student)->postJson(route('exams.attempts.save', $attempt), ['question_id' => $q2->id, 'response' => ['text' => 'x']])->assertOk();
        $this->actingAs($student)->postJson(route('exams.attempts.advance', $attempt), ['to' => 0])->assertJson(['position' => 1]);
        $this->actingAs($student)->postJson(route('exams.attempts.advance', $attempt), ['to' => 99])->assertJson(['position' => 2]);
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('exam.sequential', true)->where('exam.position', 2)->where('exam.calculator', 'scientific'));
        $this->assertSame('x', Answer::where('exam_question_id', $q1->id)->first()->response['text']);
    }

    public function test_random_questions_by_difficulty_and_tag(): void
    {
        $category = BankCategory::create(['user_id' => $this->teacher->id, 'name' => 'ك']);
        foreach ([[1, ['أحماض']], [1, ['قواعد']], [3, ['أحماض']], [3, ['أحماض']]] as $k => [$difficulty, $tags]) {
            $q = Question::create(['bank_category_id' => $category->id, 'type' => 'single', 'body' => ['ar' => "س{$k}"], 'points' => 1, 'difficulty' => $difficulty, 'tags' => $tags]);
            Choice::create(['exam_question_id' => $q->id, 'body' => ['ar' => 'أ'], 'is_correct' => true]);
            Choice::create(['exam_question_id' => $q->id, 'body' => ['ar' => 'ب'], 'is_correct' => false]);
        }
        $slot = fn (array $s) => [['type' => 'random', 'points' => 1, 'settings' => ['category_id' => $category->id] + $s]];

        $exam = Exam::create(['user_id' => $this->teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
        $put = fn (array $q) => $this->actingAs($this->teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true, 'questions' => $q,
        ]);
        $put($slot(['count' => 3, 'difficulty' => 3]))->assertSessionHasErrors('questions.0.settings');   // only 2 hard ones
        $put($slot(['count' => 2, 'difficulty' => 3, 'tag' => 'أحماض']))->assertSessionHasNoErrors();

        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $drawn = array_keys(Attempt::firstOrFail()->drawn);
        $this->assertEqualsCanonicalizing(Question::where('difficulty', 3)->pluck('id')->all(), $drawn);

        // Tags and difficulty are kept on the exam's own questions too.
        $put([['type' => 'short', 'points' => 1, 'body' => ['ar' => 'س'], 'settings' => ['answers' => ['x']], 'tags' => ['الفصل 2', ' الفصل 2 ', ''], 'difficulty' => 2]])->assertSessionHasNoErrors();
        $q = $exam->questions()->first();
        $this->assertSame(['الفصل 2'], $q->tags);
        $this->assertSame(2, $q->difficulty);
    }
}
