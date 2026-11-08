<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function exam(User $teacher): Exam
    {
        return Exam::create(['user_id' => $teacher->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar']]);
    }

    public function test_questions_come_in_from_a_csv_sheet(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        $exam = $this->exam($teacher);
        $csv = "\xEF\xBB\xBF".implode("\n", [
            'النوع,السؤال,الخيار 1,الخيار 2,الخيار 3,الإجابة,الدرجة',
            'اختيار,رمز الماء؟,H2O,CO2,O2,أ,2',
            'متعدد,الغازات النبيلة,هيليوم,نيون,حديد,"أ، ب",2',
            'صح وخطأ,الماء يغلي عند 100,,,,صح,1',
            'قصيرة,عاصمة العراق,,,,بغداد|بغدادُ,1',
            'رقمي,قيمة π,,,,3.14±0.01,1',
            'مقالي,اشرح التأكسد,,,,,5',
            'اختيار,سؤال بإجابة غير مفهومة,أ1,ب1,,ي,1',
        ]);

        $this->actingAs($teacher)->post(route('exams.manage.import', $exam), [
            'file' => UploadedFile::fake()->createWithContent('questions.csv', $csv),
        ])->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'أُضيف 6') && str_contains($m, 'سطر 8'));

        $questions = $exam->questions()->with('choices')->get();
        $this->assertSame(['single', 'multiple', 'true_false', 'short', 'numeric', 'essay'], $questions->pluck('type')->all());
        $this->assertTrue($questions[0]->choices[0]->is_correct);
        $this->assertSame([true, true, false], $questions[1]->choices->pluck('is_correct')->all());
        $this->assertSame('صح', $questions[2]->choices->firstWhere('is_correct', true)->body['ar']);
        $this->assertSame(['بغداد', 'بغدادُ'], $questions[3]->settings['answers']);
        $this->assertSame(['answer' => '3.14', 'tolerance' => '0.01'], $questions[4]->settings);
        $this->assertSame(5.0, $questions[5]->points);
    }

    public function test_questions_come_in_from_an_aiken_file(): void
    {
        $teacher = User::factory()->create(['is_examiner' => true]);
        $exam = $this->exam($teacher);
        $aiken = "ما عاصمة العراق؟\nA. البصرة\nB. بغداد\nC. الموصل\nANSWER: B\n\nأيّ مما يلي فلز؟\nأ) الحديد\nب) الأوكسجين\nالإجابة: أ\n";

        $this->actingAs($teacher)->post(route('exams.manage.import', $exam), [
            'file' => UploadedFile::fake()->createWithContent('q.txt', $aiken),
        ])->assertSessionHasNoErrors();

        $questions = $exam->questions()->with('choices')->get();
        $this->assertCount(2, $questions);
        $this->assertSame('بغداد', $questions[0]->choices->firstWhere('is_correct', true)->body['ar']);
        $this->assertSame('الحديد', $questions[1]->choices->firstWhere('is_correct', true)->body['ar']);
    }

    public function test_other_teachers_cannot_import_into_an_exam(): void
    {
        $exam = $this->exam(User::factory()->create(['is_examiner' => true]));
        $other = User::factory()->create(['is_examiner' => true]);
        $this->actingAs($other)->post(route('exams.manage.import', $exam), [
            'file' => UploadedFile::fake()->createWithContent('q.csv', "السؤال\nس"),
        ])->assertNotFound();
    }

    public function test_admin_imports_students_and_gets_their_passwords(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        User::factory()->create(['email' => 'taken@example.com']);
        $csv = "الاسم,البريد,كلمة المرور\nعلي حسن,ali@example.com,\nسارة,sara@example.com,secret123\nمكرر,taken@example.com,\nخطأ,not-an-email,\n";

        $response = $this->actingAs($admin)->post(route('exams.manage.students.import'), [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv),
        ]);
        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringContainsString('ali@example.com', $body);
        $this->assertStringContainsString('تُخطّي: موجود مسبقاً', $body);
        $this->assertStringContainsString('تُخطّي: بريد غير صالح', $body);

        $this->assertTrue(User::where('email', 'ali@example.com')->exists());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret123', User::where('email', 'sara@example.com')->first()->password));

        $teacher = User::factory()->create(['is_examiner' => true]);
        $this->actingAs($teacher)->post(route('exams.manage.students.import'), [
            'file' => UploadedFile::fake()->createWithContent('s.csv', $csv),
        ])->assertForbidden();
    }
}
