<?php

namespace Tests\Feature\Admin;

use App\Models\Exams\Group;
use App\Models\User;
use App\Services\LoginCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LoginCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/login-cards'));
        parent::tearDown();
    }

    public function test_cards_for_ticked_students_with_new_passwords_and_select_all(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $students = collect(['علي', 'سارة', 'زينب'])->map(fn ($n, $i) => User::factory()->create(['name' => $n, 'student_number' => '2025'.$i]));
        $teacher = User::factory()->create(['name' => 'أستاذ', 'is_examiner' => true]);
        $group = Group::create(['user_id' => $admin->id, 'name' => 'المرحلة الأولى']);
        $group->members()->attach($students->take(2)->pluck('id'));

        $this->actingAs($admin)->get(route('admin.cards.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/LoginCards')->where('groups.0.count', 2));
        // The list: students only by default; «تحديد كل النتائج» gives every id of a search or a group.
        $this->assertSame(3, $this->actingAs($admin)->getJson(route('admin.cards.users'))->json('total'));
        $this->assertCount(2, $this->actingAs($admin)->getJson(route('admin.cards.users', ['group' => $group->id, 'ids' => 1]))->json('ids'));
        $this->assertSame([$teacher->id], $this->actingAs($admin)->getJson(route('admin.cards.users', ['who' => 'staff', 'ids' => 1]))->json('ids'));

        $old = $students[0]->password;
        $made = $this->actingAs($admin)->postJson(route('admin.cards.generate'), [
            'user_ids' => array_merge($students->pluck('id')->all(), [$admin->id]), 'new_passwords' => true, 'label' => '',
            'login_url' => 'https://exams.example.edu', 'alt_url' => 'http://192.168.1.10', 'wifi' => 'Exams-WiFi', 'note' => 'ملاحظة',
        ])->assertOk()->json();
        $this->assertSame(3, $made['count'], 'admins never get a card');
        $this->assertNotSame($old, $students[0]->fresh()->password);

        $rows = LoginCards::read(LoginCards::path($made['file']));
        $ali = collect($rows)->firstWhere('name', 'علي');
        $this->assertTrue(Hash::check($ali['password'], $students[0]->fresh()->password));
        $this->assertSame('20250', $ali['username']);
        $this->assertSame('المرحلة الأولى', $ali['label']);
        $this->assertSame('طالب', collect($rows)->firstWhere('name', 'زينب')['label']);
        $this->assertSame($students[0]->fresh()->card_code, $ali['card_code']);
        $this->assertDoesNotMatchRegularExpression('/[0o1li]/', $ali['password']);

        $this->actingAs($admin)->get(route('admin.cards.print', $made['file']))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/LoginCardsPrint')->has('cards', 3)->where('settings.wifi', 'Exams-WiFi'));
        $this->actingAs($admin)->get(route('admin.cards.download', $made['file']))->assertOk();
        $this->actingAs($admin)->get(route('admin.cards.print', '..%2F.env'))->assertNotFound();

        // QR only: the password stays.
        $pw = $students[2]->fresh()->password;
        $qrOnly = $this->actingAs($admin)->postJson(route('admin.cards.generate'), ['user_ids' => [$students[2]->id], 'new_passwords' => false])->json();
        $this->assertSame($pw, $students[2]->fresh()->password);
        $this->assertSame('', LoginCards::read(LoginCards::path($qrOnly['file']))[0]['password']);

        // The offline server takes the same passwords and QR codes from the file.
        $students[0]->forceFill(['password' => Hash::make('something-else'), 'card_code' => null])->save();
        $csv = UploadedFile::fake()->createWithContent('cards.csv', file_get_contents(LoginCards::path($made['file'])));
        $this->actingAs($admin)->postJson(route('admin.cards.apply'), ['file' => $csv])->assertOk()->assertJson(['updated' => 3]);
        $this->assertTrue(Hash::check($ali['password'], $students[0]->fresh()->password));
        $this->assertSame($ali['card_code'], $students[0]->fresh()->card_code);

        $this->actingAs($admin)->delete(route('admin.cards.destroy', $made['file']))->assertRedirect();
        $this->assertNull(LoginCards::path($made['file']));

        $this->actingAs($students[0])->get(route('admin.cards.index'))->assertForbidden();
    }
}
