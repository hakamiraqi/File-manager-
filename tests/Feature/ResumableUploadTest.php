<?php

namespace Tests\Feature;

use App\Models\FileUploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResumableUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_calling_init_again_for_the_same_file_resumes_the_existing_session_instead_of_starting_over(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson(route('uploads.init'), [
            'name' => 'movie.mp4',
            'size' => 20 * 1024 * 1024,
        ])->assertOk()->json();

        $this->assertFalse($first['resumed']);
        $this->assertSame([], $first['received_chunks']);

        // Upload the first chunk, then simulate the browser reloading and
        // the user re-selecting the exact same file: it should reattach to
        // the same upload session instead of creating a second one.
        $this->actingAs($user)->post(route('uploads.chunk'), [
            'identifier' => $first['identifier'],
            'index' => 0,
            'chunk' => UploadedFile::fake()->create('chunk0', 1),
        ])->assertOk();

        $second = $this->actingAs($user)->postJson(route('uploads.init'), [
            'name' => 'movie.mp4',
            'size' => 20 * 1024 * 1024,
        ])->assertOk()->json();

        $this->assertTrue($second['resumed']);
        $this->assertSame($first['identifier'], $second['identifier']);
        $this->assertSame([0], $second['received_chunks']);
        $this->assertSame(1, FileUploadSession::count());
    }

    public function test_a_different_file_does_not_resume_an_unrelated_upload_session(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('uploads.init'), [
            'name' => 'a.mp4',
            'size' => 20 * 1024 * 1024,
        ])->assertOk();

        $response = $this->actingAs($user)->postJson(route('uploads.init'), [
            'name' => 'b.mp4',
            'size' => 20 * 1024 * 1024,
        ])->assertOk()->json();

        $this->assertFalse($response['resumed']);
        $this->assertSame(2, FileUploadSession::count());
    }

    public function test_a_finished_upload_is_not_offered_for_resuming(): void
    {
        Storage::fake(config('filenest.disk'));
        $user = User::factory()->create();
        $content = str_repeat('a', 10);

        $init = $this->actingAs($user)->postJson(route('uploads.init'), [
            'name' => 'note.txt',
            'size' => strlen($content),
        ])->assertOk()->json();

        $this->actingAs($user)->post(route('uploads.chunk'), [
            'identifier' => $init['identifier'],
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk0', $content),
        ])->assertOk();

        $this->actingAs($user)->postJson(route('uploads.finalize'), [
            'identifier' => $init['identifier'],
        ])->assertOk();

        $again = $this->actingAs($user)->postJson(route('uploads.init'), [
            'name' => 'note.txt',
            'size' => strlen($content),
        ])->assertOk()->json();

        $this->assertFalse($again['resumed']);
        $this->assertNotSame($init['identifier'], $again['identifier']);
    }

    public function test_another_users_upload_session_is_never_resumed(): void
    {
        Storage::fake(config('filenest.disk'));
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)->postJson(route('uploads.init'), [
            'name' => 'shared-name.bin',
            'size' => 20 * 1024 * 1024,
        ])->assertOk();

        $response = $this->actingAs($other)->postJson(route('uploads.init'), [
            'name' => 'shared-name.bin',
            'size' => 20 * 1024 * 1024,
        ])->assertOk()->json();

        $this->assertFalse($response['resumed']);
    }
}
