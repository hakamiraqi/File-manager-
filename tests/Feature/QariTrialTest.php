<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** «تجربة Qari»: a page read by the Arabic model, kept apart from the library's reading. */
class QariTrialTest extends TestCase
{
    use RefreshDatabase;

    private function page(): UploadedFile
    {
        $img = imagecreatetruecolor(2400, 3200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return UploadedFile::fake()->createWithContent('page.png', ob_get_clean());
    }

    public function test_only_the_main_admin_can_use_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => false]);
        $this->actingAs($admin)->get('/admin/qari')->assertForbidden();
        $this->actingAs($admin)->post('/admin/qari', ['file' => $this->page(), 'engines' => ['qari-2b']])->assertForbidden();
    }

    public function test_a_page_is_read_by_qari_and_the_time_is_kept(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok']),
            '*/v1/chat/completions' => function ($request) {
                // The picture is sent scaled down (bigger pictures cost much more time).
                $url = $request['messages'][0]['content'][0]['image_url']['url'];
                $size = getimagesizefromstring(base64_decode(substr($url, strlen('data:image/jpeg;base64,'))));
                $this->assertLessThanOrEqual(1600, max($size[0], $size[1]));

                return Http::response(['choices' => [['message' => ['content' => " بسم الله الرحمن الرحيم \n"]]], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 12]]);
            },
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);

        $this->actingAs($admin)->get('/admin/qari')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/QariTrial')->where('models.0.key', 'qari-2b')->where('models.0.up', true));

        $id = $this->actingAs($admin)->post('/admin/qari', ['file' => $this->page(), 'engines' => ['qari-2b']])->assertOk()->json('id');

        // The queue runs at once in tests.
        $this->actingAs($admin)->getJson("/admin/qari/$id")->assertOk()
            ->assertJsonPath('results.qari-2b.state', 'done')
            ->assertJsonPath('results.qari-2b.text', 'بسم الله الرحمن الرحيم')
            ->assertJsonPath('results.qari-2b.tokens', 12);
        $this->actingAs($admin)->get("/admin/qari/$id/image")->assertOk();
    }

    public function test_a_stopped_model_is_reported_not_hidden(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $admin = User::factory()->create(['role' => 'admin', 'is_master_admin' => true]);
        $id = $this->actingAs($admin)->post('/admin/qari', ['file' => $this->page(), 'engines' => ['qari-4b']])->json('id');
        $this->actingAs($admin)->getJson("/admin/qari/$id")->assertJsonPath('results.qari-4b.state', 'failed');
    }
}
