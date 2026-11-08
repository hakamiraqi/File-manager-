<?php

namespace Tests\Feature\Exams;

use App\Models\Exams\Exam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every page of the exam system opens for the people who use it (no broken props or links). */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_page_opens(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $exam = Exam::create(['user_id' => $admin->id, 'title' => ['ar' => 'امتحان'], 'languages' => ['ar'], 'is_published' => true]);

        foreach ([
            'admin.dashboard', 'admin.users.index', 'admin.settings.index', 'admin.security.index', 'admin.mail.index',
            'admin.translations.index', 'admin.activity.index', 'exams.index', 'exams.manage.index', 'profile.edit', 'notifications.index',
        ] as $name) {
            $this->actingAs($admin)->get(route($name))->assertOk();
        }
        foreach (['exams.show', 'exams.manage.edit', 'exams.manage.results'] as $name) {
            $this->actingAs($admin)->get(route($name, $exam))->assertOk();
        }
        $this->actingAs($admin)->get(route('exams.manage.import.template'))->assertOk();
    }

    public function test_a_student_lands_on_the_exams_and_sees_no_admin_pages(): void
    {
        $student = User::factory()->create();

        $this->get('/')->assertRedirect(route('login'));
        $this->actingAs($student)->get('/')->assertRedirect(route('exams.index'));
        $this->actingAs($student)->get(route('exams.index'))->assertOk();
        $this->actingAs($student)->get(route('profile.edit'))->assertOk();
        $this->actingAs($student)->get(route('admin.dashboard'))->assertForbidden();
    }
}
