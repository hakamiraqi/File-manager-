<?php

namespace Tests\Feature\Exams;

use App\Console\Commands\CreateDemoExam;
use App\Models\Exams\Attempt;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DemoExamTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_trial_exam_has_every_type_and_the_student_can_sit_it(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $student = User::factory()->create(['email' => 'student@exams.test']);

        $this->artisan('exams:demo')->assertSuccessful();
        $exam = Exam::firstOrFail();
        $this->assertSame(CreateDemoExam::TITLE, $exam->title['ar']);
        $this->assertEqualsCanonicalizing(Question::TYPES, $exam->questions()->pluck('type')->all());
        $this->assertSame(5, $exam->maxAttemptsFor($student));   // the only student is linked

        // Running it again keeps it; --fresh replaces it.
        $this->artisan('exams:demo')->assertSuccessful();
        $this->assertSame(1, Exam::count());
        $this->artisan('exams:demo', ['--fresh' => true])->assertSuccessful();
        $this->assertSame(1, Exam::count());
        $exam = Exam::firstOrFail();

        $this->actingAs($student)->get(route('exams.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('exams', 1));
        $this->actingAs($student)->post(route('exams.start', $exam))->assertRedirect();
        $attempt = Attempt::firstOrFail();
        $this->actingAs($student)->get(route('exams.attempts.take', $attempt))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('questions', count(Question::TYPES) - 1 + 2));   // every type but the random slot, and 2 drawn from the bank
        $this->actingAs($student)->get(route('exams.image', $exam->questions()->where('type', 'hotspot')->first()->settings['image']))->assertOk();
        $this->actingAs($student)->post(route('exams.attempts.submit', $attempt))->assertRedirect();
        $this->assertSame(0.0, $attempt->fresh()->score);
    }
}
