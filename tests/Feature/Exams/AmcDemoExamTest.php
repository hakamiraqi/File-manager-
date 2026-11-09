<?php

namespace Tests\Feature\Exams;

use App\Console\Commands\CreateAmcDemoExam;
use App\Models\Exams\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmcDemoExamTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hundred_question_exam(): void
    {
        config(['services.amc.url' => null]);
        User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        User::factory()->count(3)->create();
        $this->artisan('exams:amc-demo')->assertSuccessful();
        $exam = Exam::where('title->ar', CreateAmcDemoExam::TITLE)->firstOrFail();
        $this->assertSame(100, $exam->questions()->count());
        $this->assertSame(['multiple' => 10, 'single' => 70, 'true_false' => 20], $exam->questions()->get()->countBy('type')->sortKeys()->all());
        $this->assertTrue($exam->questions()->with('choices')->get()->every(fn ($q) => $q->choices->where('is_correct', true)->count() >= 1));
        $this->assertSame(3, $exam->roster()->count());
        $this->assertStringContainsString('ملح الطعام', app(\App\Services\Exams\AutoMultipleChoice::class)->source($exam));

        $this->artisan('exams:amc-demo')->assertSuccessful();
        $this->assertSame(1, Exam::where('title->ar', CreateAmcDemoExam::TITLE)->count());
        $this->artisan('exams:amc-demo --fresh')->assertSuccessful();
        $this->assertSame(1, Exam::where('title->ar', CreateAmcDemoExam::TITLE)->count());
    }
}
