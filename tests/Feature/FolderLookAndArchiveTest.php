<?php

namespace Tests\Feature;

use App\Jobs\ExtractFileText;
use App\Models\ArchiveRule;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Services\Archive\ArchiveClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FolderLookAndArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const LETTER = "جمهورية العراق\nجامعة تكريت / كلية طب الأسنان\nالعدد: ٤٥٦/٣/١٢\nالتاريخ: ٢٠/١١/٢٠٢٦\nالوارد\nم/ طلب نقل خدمات\nإلى/ رئاسة الجامعة\nنرفق لكم ... مع التقدير";

    public function test_a_folder_gets_a_theme_colour_logo_and_cover_and_passes_them_down(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $college = Folder::factory()->create(['user_id' => $user->id, 'name' => 'طب الأسنان']);
        $inner = Folder::factory()->create(['user_id' => $user->id, 'parent_id' => $college->id, 'name' => 'الصادر']);

        $this->actingAs($user)->post(route('folders.appearance', $college), [
            'theme' => 'tu-dentistry',
            'color' => '#6d28d9',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'cover' => UploadedFile::fake()->image('cover.jpg', 1600, 500),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $college->refresh();
        $this->assertSame('tu-dentistry', $college->theme);
        $this->assertSame('#6d28d9', $college->color);
        Storage::disk('local')->assertExists($college->logo_path);
        $this->actingAs($user)->get($college->logo_url)->assertOk();

        // The folder inside takes the college's look.
        $this->actingAs($user)->get(route('dashboard', ['folder' => $inner->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('folderLook.theme', 'tu-dentistry')
                ->where('folderLook.color', '#6d28d9')
                ->where('folderLook.inherited_from.id', $college->id)
                ->where('folderLook.own.theme', null));

        // Removing the cover deletes the picture.
        $cover = $college->cover_path;
        $this->actingAs($user)->post(route('folders.appearance', $college), ['remove_cover' => 1])->assertRedirect();
        Storage::disk('local')->assertMissing($cover);
        $this->assertNull($college->fresh()->cover_path);

        // A bad colour is refused; strangers can't see the logo.
        $this->actingAs($user)->post(route('folders.appearance', $college), ['color' => 'red'])->assertSessionHasErrors('color');
        $this->actingAs(User::factory()->create())->get($college->fresh()->logo_url)->assertNotFound();
    }

    public function test_words_sort_a_letter_and_read_its_number_date_and_subject(): void
    {
        $c = app(ArchiveClassifier::class);
        $matches = $c->matches(self::LETTER);
        $this->assertSame('وارد', $matches[0]['rule']->name);
        $this->assertSame('456/3/12', ArchiveClassifier::reference(self::LETTER));
        $this->assertSame('2026-11-20', ArchiveClassifier::date(self::LETTER)?->toDateString());
        $this->assertSame('طلب نقل خدمات', ArchiveClassifier::subject(self::LETTER));

        // «مصادر» is not «صادر».
        $this->assertNotContains('صادر', array_map(fn ($m) => $m['rule']->name, $c->matches('قائمة مصادر البحث')));
        // A misread long word still counts (OCR: «تعميمم»).
        $this->assertSame('تعميم', $c->matches("كتاب\nتعميمم الى الاقسام كافة")[0]['rule']->name);
    }

    public function test_any_category_with_its_own_words_and_a_folder_to_file_into(): void
    {
        Storage::fake(config('filenest.disk'));
        $admin = User::factory()->create(['role' => 'admin']);
        $target = Folder::factory()->create(['user_id' => $admin->id, 'name' => 'الامتحانات']);
        $this->actingAs($admin)->post(route('archive.rules.store'), [
            'name' => 'امتحانات', 'keywords' => 'الامتحانات النهائية، جدول الامتحانات', 'match_mode' => 'any',
            'color' => '#ff0000', 'target_folder_id' => $target->id, 'priority' => 90, 'enabled' => true,
        ])->assertSessionHasNoErrors();

        $file = File::factory()->create(['user_id' => $admin->id, 'name' => 'جدول.txt', 'path' => 'a.txt', 'extension' => 'txt']);
        Storage::disk(config('filenest.disk'))->put('a.txt', "م/ جدول الامتحانات النهائية\nللعام الدراسي ٢٠٢٦");
        (new ExtractFileText($file->id))->handle(app(\App\Services\DocumentTextExtractor::class));

        $file->refresh();
        $this->assertSame('امتحانات', $file->archive_label);
        $this->assertSame($target->id, $file->folder_id);

        // Picked by hand: the automatic sorting leaves it alone.
        $outgoing = ArchiveRule::where('name', 'صادر')->first();
        $this->actingAs($admin)->putJson(route('files.archive', $file), ['rule_id' => $outgoing->id])->assertOk();
        app(ArchiveClassifier::class)->classify($file->fresh());
        $this->assertSame('صادر', $file->fresh()->archive_label);

        $this->actingAs($admin)->get(route('archive.index', ['rule' => $outgoing->id]))
            ->assertInertia(fn (Assert $page) => $page->component('Archive/Index')->has('files.data', 1));
    }

    public function test_only_admins_change_the_categories(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('archive.rules.store'), ['name' => 'x', 'keywords' => 'x', 'match_mode' => 'any'])->assertForbidden();
        $this->actingAs($user)->get(route('archive.index'))->assertOk();
    }
}
