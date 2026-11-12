<?php

namespace Tests\Feature\Reader;

use App\Models\File;
use App\Models\ReaderState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PdfReaderTest extends TestCase
{
    use RefreshDatabase;

    private function pdf(User $owner): File
    {
        Storage::fake(config('filenest.disk'));
        $f = File::factory()->create([
            'user_id' => $owner->id, 'name' => 'book.pdf', 'extension' => 'pdf', 'path' => "{$owner->id}/b.pdf",
            'mime_type' => 'application/pdf', 'size' => 4,
        ]);
        $f->disk()->put($f->path, '%PDF');

        return $f->fresh();
    }

    public function test_the_reader_opens_with_the_readers_own_place_bookmarks_and_notes(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $file = $this->pdf($owner);

        $this->actingAs($owner)->get(route('reader.show', $file))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Reader/Show')
                ->where('src', route('files.preview', $file->id))
                ->where('state.last_page', 1)
                ->where('signature', null));

        $notes = [
            ['id' => 'a1', 'type' => 'highlight', 'page' => 3, 'color' => '#facc15', 'rects' => [[10, 20, 300, 18]], 'text' => 'مرحبا'],
            ['id' => 'a2', 'type' => 'ink', 'page' => 4, 'color' => '#e11d48', 'size' => 4, 'points' => [[1, 2], [3, 4]]],
        ];
        $this->putJson(route('reader.state.save', $file), [
            'last_page' => 37, 'pages' => 120,
            'bookmarks' => [['page' => 9, 'title' => 'الفصل الثاني'], ['page' => 2, 'title' => ''], ['page' => 9, 'title' => 'dup']],
            'annotations' => $notes,
        ])->assertOk();

        $this->getJson(route('reader.state', $file))->assertOk()
            ->assertJsonPath('state.last_page', 37)
            ->assertJsonPath('state.bookmarks.0.page', 2)
            ->assertJsonCount(2, 'state.bookmarks')
            ->assertJsonPath('state.annotations.0.text', 'مرحبا');

        // Only the place changes: the rest stays.
        $this->putJson(route('reader.state.save', $file), ['last_page' => 40])->assertOk();
        $s = ReaderState::first();
        $this->assertSame(40, $s->last_page);
        $this->assertCount(2, $s->annotations);

        // Another reader has their own, empty state.
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($other)->getJson(route('reader.state', $file))->assertOk()->assertJsonPath('state.last_page', 1)->assertJsonCount(0, 'state.annotations');

        // Unknown kinds of notes are refused.
        $this->actingAs($owner)->putJson(route('reader.state.save', $file), ['annotations' => [['type' => 'script', 'page' => 1]]])->assertUnprocessable();
    }

    public function test_someone_without_access_can_neither_read_nor_write_notes(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $file = $this->pdf($owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('reader.show', $file))->assertForbidden();
        $this->actingAs($stranger)->putJson(route('reader.state.save', $file), ['last_page' => 2])->assertForbidden();
        $this->assertSame(0, ReaderState::count());
    }

    public function test_the_signature_is_kept_per_user_and_can_be_removed(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $file = $this->pdf($user);
        $strokes = [[[10, 10], [50, 60], [90, 20]]];

        $this->actingAs($user)->putJson(route('reader.signature'), ['strokes' => $strokes])->assertOk();
        $this->getJson(route('reader.state', $file))->assertJsonPath('signature', $strokes);
        $this->deleteJson(route('reader.signature'))->assertOk();
        $this->getJson(route('reader.state', $file))->assertJsonPath('signature', null);
    }
}
