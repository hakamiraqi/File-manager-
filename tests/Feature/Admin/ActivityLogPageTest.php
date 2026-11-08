<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActivityLogPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function log(User $user, string $action, string $at, ?string $text = null, $subject = null): void
    {
        ActivityLog::forceCreate([
            'user_id' => $user->id, 'action' => $action, 'description' => $text, 'created_at' => $at,
            'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(),
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1',
        ]);
    }

    public function test_the_log_filters_by_period_person_role_kind_action_and_text(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00'); // 12:00 Baghdad
        $admin = User::factory()->create(['role' => 'admin', 'can_view_statistics' => true]);
        $sara = User::factory()->create(['role' => 'editor', 'name' => 'سارة']);
        $ali = User::factory()->create(['role' => 'user', 'name' => 'علي']);
        $file = File::factory()->create(['user_id' => $sara->id, 'name' => 'بحث.pdf']);

        $this->log($sara, 'file.uploaded', '2026-11-05 06:00:00', 'رفع الملف "بحث.pdf"', $file);
        $this->log($sara, 'folder.created', '2026-11-05 06:10:00', 'أنشأ مجلد التقارير');
        $this->log($ali, 'file.downloaded', '2026-11-05 07:00:00', 'حمّل الملف "بحث.pdf"', $file);
        $this->log($ali, 'library.loan', '2026-11-01 07:00:00', 'إعارة كتاب');

        $page = fn (array $q) => $this->actingAs($admin)->get(route('admin.activity.index', $q))->assertOk();

        $page([])->assertInertia(fn ($p) => $p
            ->component('Admin/ActivityLog')
            ->where('logs.total', 4)
            ->where('kindCounts.all', 4)
            ->where('kindCounts.uploads', 1)
            ->where('kindCounts.circulation', 1)
            ->where('logs.data.0.label', 'تنزيل ملف')
            ->where('logs.data.0.kind', 'downloads')
            ->where('logs.data.0.user.name', 'علي')
            ->where('logs.data.0.subject.name', 'بحث.pdf'));

        $page(['preset' => 'today'])->assertInertia(fn ($p) => $p->where('logs.total', 3)->where('kindCounts.circulation', 0));
        $page(['user' => $sara->id])->assertInertia(fn ($p) => $p->where('logs.total', 2));
        $page(['role' => 'user'])->assertInertia(fn ($p) => $p->where('logs.total', 2));
        $page(['kind' => 'data_entry'])->assertInertia(fn ($p) => $p->where('logs.total', 1)->where('kindCounts.all', 4));
        $page(['action' => 'library.loan'])->assertInertia(fn ($p) => $p->where('logs.total', 1));
        $page(['q' => 'التقارير'])->assertInertia(fn ($p) => $p->where('logs.total', 1));
        $page(['q' => 'علي'])->assertInertia(fn ($p) => $p->where('logs.total', 2));

        $csv = $this->actingAs($admin)->get(route('admin.activity.export', ['user' => $ali->id]));
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('إعارة', $body);
        $this->assertStringNotContainsString('سارة', $body);
    }

    public function test_users_are_filtered_by_role_and_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        User::factory()->create(['role' => 'editor', 'name' => 'محرر واحد']);
        User::factory()->create(['role' => 'user', 'name' => 'قارئ', 'is_active' => false]);
        User::factory()->create(['role' => 'user', 'name' => 'قارئ آخر']);

        $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'user']))
            ->assertInertia(fn ($p) => $p->where('users.total', 2)->where('roleCounts.user', 2)->where('statusCounts.suspended', 1));
        $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'user', 'status' => 'suspended']))
            ->assertInertia(fn ($p) => $p->where('users.total', 1)->where('users.data.0.name', 'قارئ'));
        // Search keeps the role filter (the email match used to escape it).
        $this->actingAs($admin)->get(route('admin.users.index', ['role' => 'editor', 'q' => 'قارئ']))
            ->assertInertia(fn ($p) => $p->where('users.total', 0));
    }
}
