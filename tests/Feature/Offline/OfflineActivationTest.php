<?php

namespace Tests\Feature\Offline;

use App\Support\OfflineLicensing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The offline server is activated with an exam-system code from the shared licensing portal. */
class OfflineActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onComputer('11111111-aaaa-bbbb-cccc-000000000001');
        @unlink(OfflineLicensing::path());
    }

    protected function tearDown(): void
    {
        @unlink(OfflineLicensing::path());
        putenv('EXAMNEST_MACHINE_GUID');
        OfflineLicensing::forgetMachineId();
        parent::tearDown();
    }

    private function onComputer(string $guid): void
    {
        putenv("EXAMNEST_MACHINE_GUID=$guid");
        OfflineLicensing::forgetMachineId();
    }

    /** A licence as the licensing portal signs it. */
    private function signed(array $payload): array
    {
        $pair = sodium_crypto_sign_seed_keypair(str_repeat('k', 32));
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return [
            'payload' => $json,
            'signature' => base64_encode(sodium_crypto_sign_detached($json, sodium_crypto_sign_secretkey($pair))),
            'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    public function test_the_offline_server_works_only_once_activated_with_an_exams_code(): void
    {
        config(['offline.edition' => true, 'offline.activation' => true]);

        $this->get('/login')->assertRedirect('/activate');
        $this->get('/activate')->assertOk()->assertSee(OfflineLicensing::machineId());

        Http::fake(['*/api/v1/offline/activate' => function ($request) {
            $this->assertSame('exams', $request['product']);

            return Http::response(['license' => $this->signed([
                'code' => 'ABCD-EFGH-JKMN-PQRS', 'product' => 'exams', 'university' => 'جامعة تكريت',
                'machine_id' => OfflineLicensing::machineId(),
            ])]);
        }]);

        $this->post('/activate', ['code' => 'ABCD-EFGH-JKMN-PQRS'])->assertRedirect('/login');
        $this->get('/login')->assertOk();

        // Copied to another computer, it asks for activation again.
        $this->onComputer('33333333-aaaa-bbbb-cccc-000000000003');
        $this->get('/login')->assertRedirect('/activate');
    }

    public function test_a_clinic_system_licence_does_not_unlock_the_exam_system(): void
    {
        config(['offline.edition' => true, 'offline.activation' => true]);
        Http::fake(['*/api/v1/offline/activate' => Http::response(['license' => $this->signed([
            'code' => 'ABCD-EFGH-JKMN-PQRS', 'product' => 'dental', 'university' => 'جامعة تكريت',
            'machine_id' => OfflineLicensing::machineId(),
        ])])]);

        $this->from('/activate')->post('/activate', ['code' => 'ABCD-EFGH-JKMN-PQRS'])->assertSessionHasErrors('code');
        $this->assertFileDoesNotExist(OfflineLicensing::path());
    }

    public function test_a_refused_code_shows_the_reason(): void
    {
        config(['offline.edition' => true, 'offline.activation' => true]);
        Http::fake(['*/api/v1/offline/activate' => Http::response(['message' => 'رمز التفعيل هذا مستخدم على حاسوب آخر.'], 422)]);

        $this->from('/activate')->post('/activate', ['code' => 'ABCD-EFGH-JKMN-PQRS'])
            ->assertRedirect('/activate')->assertSessionHasErrors(['code' => 'رمز التفعيل هذا مستخدم على حاسوب آخر.']);
    }

    public function test_the_hosted_site_has_no_activation(): void
    {
        $this->get('/activate')->assertNotFound();
        $this->get('/login')->assertOk();
        $this->getJson('/brand.json')->assertOk()->assertJsonStructure(['name', 'logo']);
    }
}
