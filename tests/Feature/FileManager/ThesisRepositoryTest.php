<?php

namespace Tests\Feature\FileManager;

use App\Models\Folder;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ThesisRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function thesis(User $owner, array $attrs = []): Folder
    {
        return Folder::factory()->create(['user_id' => $owner->id, 'folder_type' => 'thesis'] + $attrs);
    }

    public function test_repository_fields_are_saved_and_the_institution_comes_from_settings(): void
    {
        Setting::setMany(['institution_university' => 'جامعة تكريت', 'institution_college' => 'كلية طب الأسنان']);
        $admin = User::factory()->create(['role' => 'admin']);
        $folder = Folder::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)->put(route('folders.update', $folder), [
            'folder_type' => 'thesis',
            'thesis_title' => 'تقييم صحة اللثة لدى طلبة الجامعة',
            'researcher_name' => 'سارة محمد',
            'degree' => 'phd',
            'department' => 'أمراض اللثة',
            'subject' => 'طب الأسنان',
            'keywords' => 'لثة، طلبة',
            'abstract' => 'نص المستخلص',
            'defense_date' => '2025-03-10',
            'language' => 'ar',
            'pages' => 140,
            'iqdr_receipt' => 'IQDR-12345',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $folder->refresh();
        $this->assertSame('جامعة تكريت', $folder->university);
        $this->assertSame('كلية طب الأسنان', $folder->college);
        $this->assertSame('2025-03-10', $folder->defense_date->format('Y-m-d'));
        $this->assertSame(140, $folder->pages);

        $this->actingAs($admin)->put(route('folders.update', $folder), ['language' => 'fr'])->assertSessionHasErrors('language');
    }

    public function test_similar_titles_are_found_despite_spelling_variants(): void
    {
        $user = User::factory()->create();
        $this->thesis($user, ['thesis_title' => 'أثر المضمضة بالكلورهيكسيدين على اللويحة الجرثومية']);

        $this->actingAs($user)->getJson(route('theses.similar', ['title' => 'اثر المضمضه بالكلورهيكسيدين علي اللويحه الجرثوميه']))
            ->assertOk()->assertJsonPath('0.similarity', 100);
        $this->actingAs($user)->getJson(route('theses.similar', ['title' => 'دراسة مختلفة تماماً عن تقويم الأسنان']))
            ->assertOk()->assertJsonCount(0);
    }

    public function test_the_catalogue_shows_what_each_viewer_may_see_with_facets(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $public = Folder::factory()->create(['user_id' => $owner->id, 'is_public' => true]);
        $this->thesis($owner, ['parent_id' => $public->id, 'thesis_title' => 'Public one', 'degree' => 'master', 'publication_year' => '2024']);
        $this->thesis($owner, ['thesis_title' => 'Private one', 'degree' => 'phd', 'publication_year' => '2023']);

        // Guests: only with guest access on, and only the public theses.
        $this->get(route('theses.index'))->assertRedirect(route('login'));
        Setting::set('guest_access_enabled', '1');
        $this->get(route('theses.index'))->assertOk()->assertInertia(fn ($page) => $page->where('theses.total', 1));

        $this->actingAs($owner)->get(route('theses.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Theses/Index')->where('facets.total', 2)->where('facets.degree.phd', 1));
        $this->actingAs($owner)->get(route('theses.index', ['degree' => 'master']))
            ->assertInertia(fn ($page) => $page->where('theses.total', 1)->where('theses.data.0.title', 'Public one'));
        $this->actingAs($other)->get(route('theses.index'))
            ->assertInertia(fn ($page) => $page->where('theses.total', 1));
    }

    public function test_export_and_import_use_the_repository_columns(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->thesis($user, ['thesis_title' => 'موجودة سابقاً في النظام', 'degree' => 'master']);

        $csv = $this->actingAs($user)->get(route('theses.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('عنوان الرسالة', $csv);
        $this->assertStringContainsString('ماجستير', $csv);

        $file = UploadedFile::fake()->createWithContent('t.csv', "\xEF\xBB\xBF"
            ."عنوان الرسالة,اسم الباحث,الدرجة,السنة,اللغة,عدد الصفحات\n"
            ."دراسة جديدة عن تسوس الأسنان,علي,دكتوراه,2022,الإنكليزية,90\n"
            ."موجوده سابقا في النظام,حسن,ماجستير,2020,,\n");
        $this->actingAs($user)->post(route('theses.import'), ['file' => $file])->assertRedirect()->assertSessionHas('success');

        $new = Folder::where('thesis_title', 'دراسة جديدة عن تسوس الأسنان')->firstOrFail();
        $this->assertSame(['phd', 'en', 90, 'thesis'], [$new->degree, $new->language, $new->pages, $new->folder_type]);
        $this->assertSame(2, Folder::where('folder_type', 'thesis')->count()); // the duplicate was skipped
    }

    public function test_oai_pmh_lists_public_theses_as_dublin_core(): void
    {
        $owner = User::factory()->create();
        $public = Folder::factory()->create(['user_id' => $owner->id, 'is_public' => true]);
        $t = $this->thesis($owner, ['parent_id' => $public->id, 'thesis_title' => 'Caries & fluoride', 'researcher_name' => 'Ali', 'degree' => 'master', 'keywords' => 'caries, fluoride']);
        $this->thesis($owner, ['thesis_title' => 'Hidden']);

        $this->get('/oai?verb=Identify')->assertOk()->assertSee('<protocolVersion>2.0</protocolVersion>', false);
        $list = $this->get('/oai?verb=ListRecords&metadataPrefix=oai_dc')->assertOk()->getContent();
        $this->assertStringContainsString('<dc:title>Caries &amp; fluoride</dc:title>', $list);
        $this->assertStringContainsString('<dc:subject>fluoride</dc:subject>', $list);
        $this->assertStringNotContainsString('Hidden', $list);
        $this->assertNotFalse(simplexml_load_string($list));

        $this->get('/oai?verb=GetRecord&metadataPrefix=oai_dc&identifier=oai:localhost:thesis/'.$t->id)->assertSee('<dc:creator>Ali</dc:creator>', false);
        $this->get('/oai?verb=Nope')->assertSee('code="badVerb"', false);
        $this->get('/oai?verb=ListRecords&metadataPrefix=marc')->assertSee('cannotDisseminateFormat', false);

        Setting::setMany(['oai_enabled' => '0']);
        $this->get('/oai?verb=Identify')->assertNotFound();
    }
}
