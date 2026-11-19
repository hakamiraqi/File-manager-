<?php

namespace Tests\Feature\Library;

use App\Models\Library\Book;
use App\Models\Library\LibraryMember;
use App\Models\Library\Loan;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** «ربط نظام الطلبة»: SIS sends readers and reads clearance with a key issued in library settings. */
class SisLinkTest extends TestCase
{
    use RefreshDatabase;

    private function issueKey(): string
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('library.manage.sis-link.issue'))->assertSessionHas('sis_key');

        return session('sis_key');
    }

    private function sis(string $key): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json']);
    }

    public function test_only_admins_issue_the_key_and_it_is_stored_as_a_hash(): void
    {
        $librarian = User::factory()->create(['is_librarian' => true]);
        $this->actingAs($librarian)->post(route('library.manage.sis-link.issue'))->assertForbidden();
        $key = $this->issueKey();
        $this->assertStringStartsWith('fnsis_', $key);
        $this->assertSame(hash('sha256', $key), Setting::get('sis_link_hash'));
        $this->assertArrayNotHasKey('sis_link_hash', Setting::visibleSettings());
    }

    public function test_sis_syncs_readers_and_reads_clearance_until_revoked(): void
    {
        $this->getJson('/api/sis/v1/manifest')->assertUnauthorized();
        $key = $this->issueKey();
        $this->sis('fnsis_wrong')->getJson('/api/sis/v1/manifest')->assertUnauthorized();
        $this->sis($key)->getJson('/api/sis/v1/manifest')->assertOk()->assertJsonPath('system', 'filenest');

        LibraryMember::create(['name' => 'قديم', 'card_number' => '2025001', 'type' => 'student', 'email' => 'keep@x.test']);
        $this->sis($key)->postJson('/api/sis/v1/members', ['members' => [
            ['card_number' => '2025001', 'name' => 'علي حسن', 'stage' => '2', 'active' => true],
            ['card_number' => '2025002', 'name' => 'زينب', 'stage' => '1', 'email' => 'z@x.test', 'active' => true],
            ['card_number' => '2025003', 'name' => 'متخرج', 'active' => false],
        ]])->assertOk()->assertJson(['created' => 1, 'updated' => 1]);
        $ali = LibraryMember::where('card_number', '2025001')->first();
        $this->assertSame(['علي حسن', '2', 'keep@x.test'], [$ali->name, $ali->stage, $ali->email]);
        $this->assertFalse(LibraryMember::where('card_number', '2025003')->exists(), 'a student who left is not added');

        Carbon::setTestNow('2026-11-02 10:00:00');
        $copy = Book::create(['title' => 'Anatomy'])->copies()->create(['barcode' => 'B1']);
        Loan::create(['copy_id' => $copy->id, 'member_id' => $ali->id, 'issued_at' => now(), 'due_at' => now()->addDays(3)]);
        Carbon::setTestNow('2026-11-19 10:00:00');

        $res = $this->sis($key)->postJson('/api/sis/v1/clearance', ['cards' => ['2025001', '2025002', '999']])->assertOk();
        $this->assertSame(1, $res->json('members.2025001.active_loans'));
        $this->assertSame(1, $res->json('members.2025001.overdue_loans'));
        $this->assertGreaterThan(0, $res->json('members.2025001.fines_due'));
        $this->assertSame(0, $res->json('members.2025002.active_loans'));
        $this->assertSame(['999'], $res->json('missing'));

        $this->actingAs(User::where('role', 'admin')->first())->delete(route('library.manage.sis-link.revoke'));
        $this->sis($key)->getJson('/api/sis/v1/manifest')->assertUnauthorized();
    }
}
