<?php

namespace Tests\Feature\Exams;

use App\Models\Setting;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Services\Exams\OffsiteBackup;
use App\Services\Exams\ServerWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OffsiteWatchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
    }

    public function test_the_backup_goes_off_site_encrypted_and_old_copies_are_removed(): void
    {
        $dir = sys_get_temp_dir().'/offsite-test-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/db.zip', 'PK-the-backup');
        $this->app->instance(DatabaseBackupService::class, new class($dir) extends DatabaseBackupService
        {
            public function __construct(private string $d) {}

            public function dir(): string
            {
                return $this->d;
            }

            public function create(string $suffix = ''): array
            {
                return ['filename' => 'db.zip', 'size' => 13];
            }
        });

        $this->actingAs($this->admin())->post(route('exams.monitor.central.offsite'), ['url' => 'https://acc.r2.cloudflarestorage.com', 'bucket' => 'exams-backup',
            'region' => 'auto', 'key_id' => 'AKID', 'secret' => 'SECRET', 'pass' => 'a long passphrase', 'keep' => 30])->assertSessionHasNoErrors();
        $this->assertTrue(OffsiteBackup::configured());
        $this->assertNotSame('SECRET', Setting::get('offsite_secret'));

        $old = now()->subDays(40)->toIso8601String();
        $new = now()->subDay()->toIso8601String();
        $put = null;
        Http::fake(function (HttpRequest $r) use (&$put, $old, $new) {
            if ($r->method() === 'PUT') {
                $put = $r;

                return Http::response('', 200);
            }
            if ($r->method() === 'GET') {
                return Http::response("<?xml version=\"1.0\"?><ListBucketResult><Contents><Key>examnest-old.zip.enc</Key><LastModified>{$old}</LastModified><Size>5</Size></Contents>"
                    ."<Contents><Key>examnest-new.zip.enc</Key><LastModified>{$new}</LastModified><Size>5</Size></Contents></ListBucketResult>");
            }

            return Http::response('', 204);
        });

        $this->artisan('backup:offsite')->assertSuccessful();
        $this->assertNotNull($put);
        $this->assertStringStartsWith('https://acc.r2.cloudflarestorage.com/exams-backup/examnest-', $put->url());
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKID/', $put->header('Authorization')[0]);
        $this->assertStringNotContainsString('PK-the-backup', $put->body());
        $this->assertSame('PK-the-backup', OffsiteBackup::decrypt($put->body(), 'a long passphrase'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/examnest-old.zip.enc'));
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/examnest-new.zip.enc'));
        $this->assertNotNull(Setting::get('offsite_last_ok'));

        // A downloaded copy opens with the passphrase.
        file_put_contents($dir.'/copy.zip.enc', $put->body());
        $this->artisan('backup:offsite', ['--decrypt' => $dir.'/copy.zip.enc', '--pass' => 'a long passphrase'])->assertSuccessful();
        $this->assertSame('PK-the-backup', file_get_contents($dir.'/copy.zip'));
        $this->expectException(\RuntimeException::class);
        OffsiteBackup::decrypt($put->body(), 'wrong');
    }

    public function test_the_server_alerts_once_and_again_when_fixed(): void
    {
        Setting::setMany(['watch_ntfy' => 'https://ntfy.sh/examnest-test', 'amc_url' => 'http://amc:8080', 'amc_token' => Crypt::encryptString('t')]);
        $amcUp = false;
        Http::fake(function (HttpRequest $r) use (&$amcUp) {
            if (str_contains($r->url(), 'amc:8080')) {
                return $amcUp ? Http::response(['ok' => true]) : Http::response('', 502);
            }

            return Http::response('{}');
        });
        $ntfy = fn () => collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'ntfy.sh'))->values();

        $this->artisan('exams:watch')->assertSuccessful();
        $this->assertCount(1, $ntfy());
        $this->assertStringContainsString('AMC', $ntfy()[0][0]->body());
        $this->assertSame('high', $ntfy()[0][0]->header('Priority')[0]);

        $this->artisan('exams:watch')->assertSuccessful();
        $this->assertCount(1, $ntfy(), 'not repeated within six hours');

        $amcUp = true;
        $this->artisan('exams:watch')->assertSuccessful();
        $this->assertCount(2, $ntfy());
        $this->assertStringContainsString('تم الإصلاح', $ntfy()[1][0]->body());

        $this->actingAs($this->admin())->get(route('exams.monitor.central'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('watch.ntfy', 'https://ntfy.sh/examnest-test')->where('watch.problems', [])->where('offsite.has_pass', false));
    }

    public function test_only_admins_set_backups_and_alerts(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user)->post(route('exams.monitor.central.offsite'), ['url' => 'https://x.example'])->assertForbidden();
        $this->actingAs($user)->post(route('exams.monitor.central.watch'), ['ntfy' => 'https://ntfy.sh/x'])->assertForbidden();
    }
}
