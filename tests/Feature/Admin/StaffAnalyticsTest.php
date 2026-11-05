<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Analytics\StaffAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StaffAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function log(User $user, string $action, string $at, $subject = null): void
    {
        ActivityLog::create([
            'user_id' => $user->id, 'action' => $action, 'created_at' => Carbon::parse($at),
            'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(),
        ]);
    }

    public function test_staff_are_compared_with_categories_quality_and_previous_period(): void
    {
        // 2026-11-05 12:00 Baghdad = 09:00 UTC (Thursday).
        Carbon::setTestNow('2026-11-05 09:00:00');
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'المدير']);
        $sara = User::factory()->create(['role' => 'editor', 'name' => 'سارة']);
        $ali = User::factory()->create(['role' => 'editor', 'name' => 'علي']);

        File::factory()->count(3)->create(['user_id' => $sara->id, 'extension' => 'pdf', 'size' => 1048576, 'created_at' => '2026-11-05 06:00:00']);
        File::factory()->create(['user_id' => $ali->id, 'extension' => 'jpg', 'size' => 100, 'created_at' => '2026-11-05 07:00:00']);
        foreach (range(1, 3) as $i) {
            $this->log($sara, 'file.uploaded', '2026-11-05 06:00:00');
        }
        $this->log($ali, 'file.uploaded', '2026-11-05 07:00:00');
        $this->log($ali, 'library.loan', '2026-11-05 07:30:00');
        // Yesterday counts for the previous period only.
        $this->log($ali, 'file.uploaded', '2026-11-04 07:00:00');
        $this->log($ali, 'file.uploaded', '2026-11-04 08:00:00');

        $full = Folder::factory()->create([
            'user_id' => $sara->id, 'folder_type' => 'thesis', 'thesis_title' => 'ع', 'researcher_name' => 'ب', 'degree' => 'master',
            'supervisor_name' => 'م', 'publication_year' => '2025', 'department' => 'ق', 'subject' => 'س', 'keywords' => 'ك',
            'abstract' => 'م', 'defense_date' => '2025-01-01', 'language' => 'ar', 'pages' => 100, 'created_at' => '2026-11-05 06:30:00',
        ]);
        $half = Folder::factory()->create([
            'user_id' => $ali->id, 'folder_type' => 'thesis', 'thesis_title' => 'ع', 'researcher_name' => 'ب', 'degree' => 'phd',
            'supervisor_name' => 'م', 'publication_year' => '2024', 'department' => 'ق', 'created_at' => '2026-11-01 06:30:00',
        ]);
        $this->log($sara, 'folder.updated', '2026-11-05 06:40:00', $full);
        $this->log($ali, 'folder.updated', '2026-11-05 07:40:00', $half);

        $this->actingAs($admin)->get(route('admin.analytics.index', ['preset' => 'today']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Analytics')
                ->where('filters.preset', 'today')
                ->where('report.kpis.uploads.value', 4)
                ->where('report.kpis.uploads.previous', 2)
                ->where('report.kpis.circulation.value', 1)
                ->where('report.kpis.data_entry.value', 2)
                ->where('report.kpis.active_staff.value', 2)
                ->where('report.staff.0.name', 'سارة')
                ->where('report.staff.0.files', 3)
                ->where('report.staff.0.index', 100)
                ->where('report.staff.0.quality', 100)
                ->where('report.staff.1.name', 'علي')
                ->where('report.staff.1.uploads', 1)
                ->where('report.staff.1.circulation', 1)
                ->where('report.staff.1.previous', 2)
                ->where('report.staff.1.quality', 50)
                ->where('report.quality.records', 2)
                ->where('report.quality.score', 75)
                ->where('report.fileTypes.0.type', 'pdf')
                ->where('report.fileTypes.0.files', 3)
                ->where('report.hours.9', 3)
                ->where('report.weekdays.5', 7)
            );

        $this->actingAs($admin)->get(route('admin.analytics.index', ['preset' => 'today', 'user' => $ali->id]))
            ->assertInertia(fn ($page) => $page->where('report.kpis.uploads.value', 1)->has('report.staff', 1));

        $csv = $this->actingAs($admin)->get(route('admin.analytics.export', ['preset' => 'today']));
        $csv->assertOk();
        $this->assertStringContainsString('سارة', $csv->streamedContent());
    }

    public function test_presets_follow_the_baghdad_calendar(): void
    {
        Carbon::setTestNow('2026-11-05 09:00:00'); // Thursday 12:00 Baghdad
        $a = app(StaffAnalytics::class);

        $p = $a->period('last_week');
        $this->assertSame('2026-10-24 00:00', $p['from']->format('Y-m-d H:i')); // Saturday
        $this->assertSame('2026-10-31 00:00', $p['to']->format('Y-m-d H:i'));

        $p = $a->period('last_month');
        $this->assertSame('2026-10-01', $p['from']->toDateString());
        $this->assertSame('2026-11-01', $p['to']->toDateString());

        $p = $a->period('shift');
        $this->assertSame('2026-11-05 08:00', $p['from']->format('Y-m-d H:i'));
        $this->assertSame('2026-11-05 04:00', $p['prevFrom']->format('Y-m-d H:i'));

        $p = $a->period('custom', '2026-10-10', '2026-10-12');
        $this->assertSame('2026-10-13', $p['to']->toDateString());
        $this->assertSame('2026-10-07', $p['prevFrom']->toDateString());
    }

    public function test_only_statistics_viewers_can_open_analytics(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user)->get(route('admin.analytics.index'))->assertForbidden();
    }
}
