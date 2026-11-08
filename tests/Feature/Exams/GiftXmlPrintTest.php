<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Exam;
use App\Models\User;
use App\Services\Exams\QuestionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GiftXmlPrintTest extends TestCase
{
    use RefreshDatabase;

    private const GIFT = <<<'GIFT'
// A comment
$CATEGORY: chemistry

::Q1:: ما رمز الماء؟ {=H2O ~CO2#لا ~NaCl}

الشمس نجم. {T}

أيّها غازات نبيلة؟ {~%50%هيليوم ~%50%نيون ~%-100%حديد}

عاصمة العراق؟ {=بغداد =Baghdad}

قيمة π لرقمين {#3.14:0.01}

طابق {=Na -> صوديوم =K -> بوتاسيوم}

اشرح التأكسد. {}

اقرأ النص التالي ثم أجب.

قيمة x\: {#1..3}
GIFT;

    public function test_gift_questions_of_every_kind(): void
    {
        $result = (new QuestionImporter)->fromGift(self::GIFT, 'ar');
        $q = array_values($result['questions']);

        $this->assertSame([], $result['skipped']);
        $this->assertSame(['single', 'true_false', 'multiple', 'short', 'numeric', 'matching', 'essay', 'description', 'numeric'], array_column($q, 'type'));
        $this->assertSame('ما رمز الماء؟', $q[0]['body']['ar']);
        $this->assertSame(['H2O', 'CO2', 'NaCl'], array_map(fn ($c) => $c['body']['ar'], $q[0]['choices']));
        $this->assertSame([true, false, false], array_column($q[0]['choices'], 'is_correct'));
        $this->assertSame([true, false], array_column($q[1]['choices'], 'is_correct'));
        $this->assertSame([true, true, false], array_column($q[2]['choices'], 'is_correct'));
        $this->assertSame(['بغداد', 'Baghdad'], $q[3]['settings']['answers']);
        $this->assertSame(['answer' => '3.14', 'tolerance' => '0.01'], $q[4]['settings']);
        $this->assertSame('صوديوم', $q[5]['choices'][0]['match']['ar']);
        $this->assertSame('قيمة x:', $q[8]['body']['ar']);
        $this->assertEquals(2, $q[8]['settings']['answer']);
        $this->assertEquals(1, $q[8]['settings']['tolerance']);
    }

    public function test_moodle_xml_questions(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
  <question type="category"><category><text>$course$/Chemistry</text></category></question>
  <question type="multichoice">
    <name><text>Q1</text></name>
    <questiontext format="html"><text><![CDATA[<p>ما رمز <b>الماء</b>؟</p>]]></text></questiontext>
    <generalfeedback><text>الماء H2O</text></generalfeedback>
    <defaultgrade>2</defaultgrade>
    <single>true</single>
    <answer fraction="100"><text>H2O</text></answer>
    <answer fraction="0"><text>CO2</text></answer>
  </question>
  <question type="truefalse">
    <questiontext><text>الشمس نجم</text></questiontext>
    <answer fraction="100"><text>true</text></answer>
    <answer fraction="0"><text>false</text></answer>
  </question>
  <question type="shortanswer">
    <questiontext><text>عاصمة العراق</text></questiontext>
    <answer fraction="100"><text>بغداد</text></answer>
  </question>
  <question type="numerical">
    <questiontext><text>π</text></questiontext>
    <answer fraction="100"><text>3.14</text><tolerance>0.01</tolerance></answer>
  </question>
  <question type="matching">
    <questiontext><text>طابق</text></questiontext>
    <subquestion><text>Na</text><answer><text>صوديوم</text></answer></subquestion>
    <subquestion><text>K</text><answer><text>بوتاسيوم</text></answer></subquestion>
  </question>
  <question type="essay"><questiontext><text>اشرح</text></questiontext></question>
  <question type="ddwtos"><questiontext><text>اسحب</text></questiontext></question>
</quiz>
XML;
        $result = (new QuestionImporter)->fromMoodleXml($xml, 'ar');
        $q = array_values($result['questions']);

        $this->assertSame(['single', 'true_false', 'short', 'numeric', 'matching', 'essay'], array_column($q, 'type'));
        $this->assertSame('ما رمز الماء؟', $q[0]['body']['ar']);
        $this->assertEquals(2, $q[0]['points']);
        $this->assertSame('الماء H2O', $q[0]['feedback']['ar']);
        $this->assertSame([true, false], array_column($q[1]['choices'], 'is_correct'));
        $this->assertSame(['بغداد'], $q[2]['settings']['answers']);
        $this->assertSame('0.01', $q[3]['settings']['tolerance']);
        $this->assertCount(2, $q[4]['choices']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(['questions' => [], 'skipped' => [1 => 'ليس ملف Moodle XML (يبدأ بـ <quiz>).']], (new QuestionImporter)->fromMoodleXml('<html/>', 'ar'));
    }

    public function test_a_gift_file_goes_into_an_exam_and_prints(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);

        $this->actingAs($teacher)->post(route('exams.manage.import', $exam), [
            'file' => UploadedFile::fake()->createWithContent('questions.txt', self::GIFT),
        ])->assertSessionHasNoErrors();
        $this->assertSame(9, $exam->questions()->count());

        $this->actingAs($teacher)->get(route('exams.manage.print', $exam))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Exams/Manage/Print')->where('answerKey', false)
                ->has('questions', 9)->where('questions.0.choices.0.is_correct', false)->missing('questions.3.answer'));
        $this->actingAs($teacher)->get(route('exams.manage.print', ['exam' => $exam, 'key' => 1]))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('answerKey', true)
                ->where('questions.0.choices.0.is_correct', true)->where('questions.3.answer', ['بغداد', 'Baghdad']));

        $this->actingAs(User::factory()->create(['is_examiner' => true]))->get(route('exams.manage.print', $exam))->assertNotFound();
    }
}
