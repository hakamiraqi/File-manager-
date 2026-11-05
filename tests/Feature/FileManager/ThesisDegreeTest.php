<?php

namespace Tests\Feature\FileManager;

use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThesisDegreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_thesis_folder_stores_the_degree(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $folder = Folder::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)->put(route('folders.update', $folder), [
            'folder_type' => 'thesis',
            'researcher_name' => 'أحمد علي',
            'degree' => 'master',
            'publication_year' => '2025',
        ])->assertRedirect();

        $this->assertSame('master', $folder->fresh()->degree);

        $this->actingAs($admin)->put(route('folders.update', $folder), ['degree' => 'bachelor'])
            ->assertSessionHasErrors('degree');

        $this->actingAs($admin)->put(route('folders.update', $folder), ['degree' => ''])->assertRedirect();
        $this->assertNull($folder->fresh()->degree);
    }
}
