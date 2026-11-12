<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_app_gets_the_sites_staff_analytics_and_others_do_not(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $this->actingAs($admin);
        ActivityLog::record('file.uploaded', 'رفع ملفاً');

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/staff-analytics?preset=last_7')->assertOk()
            ->assertJsonPath('filters.preset', 'last_7')
            ->assertJsonStructure(['period' => ['from', 'to'], 'report' => ['kpis' => ['total', 'uploads'], 'trend' => ['labels', 'series'], 'hours', 'weekdays', 'staff', 'fileTypes', 'quality'], 'users']);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/staff-analytics')->assertForbidden();
    }
}
