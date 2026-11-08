<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\User;
use App\Services\Exams\HtmlCleaner;
use App\Services\Exams\QuestionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use ZipArchive;

class RichTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cleaner_keeps_formatting_and_drops_danger(): void
    {
        $own = '/exams/images/'.str_repeat('a', 64).'.png';
        $html = '<p dir="rtl" onclick="x()" style="color:red">نص <b>غامق</b> و H<sub>2</sub>O <a href="http://evil">رابط</a></p>'
            .'<script>alert(1)</script><iframe src="http://x"></iframe>'
            .'<table><tr><td colspan="2" onmouseover="x">خلية</td></tr></table>'
            ."<img src=\"{$own}\" onerror=\"x\"><img src=\"http://evil.test/a.png\"><audio src=\"{$own}\"></audio>"
            .'<p class="text-center">وسط</p><p class="bad">x</p>';
        $clean = HtmlCleaner::clean($html);

        $this->assertStringContainsString('<p dir="rtl">نص <b>غامق</b> و H<sub>2</sub>O رابط</p>', $clean);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=|style=/', $clean);
        $this->assertStringContainsString('<td colspan="2">خلية</td>', $clean);
        $this->assertStringContainsString("<img src=\"{$own}\">", $clean);
        $this->assertStringNotContainsString('evil', $clean);
        $this->assertStringContainsString("<audio src=\"{$own}\" controls", $clean);
        $this->assertStringContainsString('<p class="text-center">وسط</p>', $clean);
        $this->assertStringContainsString('<p>x</p>', $clean);
    }

    public function test_a_formatted_question_is_saved_clean_and_shown_as_html(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['is_examiner' => true]);
        $exam = Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);

        $sound = $this->actingAs($teacher)->post(route('exams.manage.images.store'), ['image' => UploadedFile::fake()->create('listen.mp3', 200, 'audio/mpeg')])
            ->assertOk()->json('url');
        $src = parse_url($sound, PHP_URL_PATH);
        $this->actingAs($teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => [
                ['type' => 'single', 'format' => 'html', 'points' => 1, 'body' => ['ar' => "<p>استمع ثم أجب: <script>x</script>\$x^2\$</p><audio src=\"{$src}\"></audio>"],
                    'choices' => [['body' => ['ar' => '$4$'], 'is_correct' => true], ['body' => ['ar' => '$5$'], 'is_correct' => false]]],
                ['type' => 'essay', 'format' => 'html', 'points' => 1, 'body' => ['ar' => '<p><br></p>']],
                ['type' => 'cloze', 'format' => 'html', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق [[بغداد]]']],
            ],
        ])->assertSessionHasErrors('questions.1.body');

        $this->actingAs($teacher)->put(route('exams.manage.update', $exam), [
            'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'direction' => 'auto', 'max_attempts' => 1,
            'grading_method' => 'highest', 'show_results' => 'after_submit', 'is_published' => true,
            'questions' => [
                ['type' => 'single', 'format' => 'html', 'points' => 1, 'body' => ['ar' => "<p>استمع ثم أجب: <script>x</script>\$x^2\$</p><audio src=\"{$src}\"></audio>"],
                    'choices' => [['body' => ['ar' => '$4$'], 'is_correct' => true], ['body' => ['ar' => '$5$'], 'is_correct' => false]]],
                ['type' => 'cloze', 'format' => 'html', 'points' => 1, 'body' => ['ar' => 'عاصمة العراق [[بغداد]]']],
            ],
        ])->assertSessionHasNoErrors();
        [$single, $cloze] = $exam->questions()->get()->all();
        $this->assertSame('html', $single->format);
        $this->assertStringNotContainsString('script', $single->body['ar']);
        $this->assertStringContainsString('<audio src="'.$src.'" controls', $single->body['ar']);
        $this->assertSame('text', $cloze->format);   // blanks are read from plain text
        $this->assertSame('استمع ثم أجب: $x^2$', $single->plainBody());

        $student = User::factory()->create();
        $this->actingAs($student)->post(route('exams.start', $exam));
        $this->actingAs($student)->get(route('exams.attempts.take', Attempt::firstOrFail()))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('questions.0.html', true)->where('questions.1.html', false));
        $this->actingAs($student)->get(route('exams.image', basename($src)))->assertOk();
    }

    public function test_questions_from_a_word_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $p = fn (string $t) => '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($t, ENT_XML1).'</w:t></w:r></w:p>';
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .$p('ما رمز الماء؟').$p('A. H2O').$p('B. CO2').$p('ANSWER: A').$p('').$p('عاصمة العراق؟').$p('أ) بغداد').$p('ب) البصرة').$p('الإجابة: أ')
            .'</w:body></w:document>');
        $zip->close();

        $result = (new QuestionImporter)->fromFile($path, 'docx', 'ar', app(\App\Services\SpreadsheetReader::class));
        $q = array_values($result['questions']);
        $this->assertCount(2, $q);
        $this->assertSame('ما رمز الماء؟', $q[0]['body']['ar']);
        $this->assertSame([true, false], array_column($q[1]['choices'], 'is_correct'));

        // GIFT written in Word too.
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="x"><w:body>'.$p('الشمس نجم {T}').'</w:body></w:document>');
        $zip->close();
        $this->assertSame('true_false', array_values((new QuestionImporter)->fromFile($path, 'docx', 'ar', app(\App\Services\SpreadsheetReader::class))['questions'])[0]['type']);
        unlink($path);
    }
}
