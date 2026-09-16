<?php

namespace Tests\Feature;

use App\Models\File;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_index_lists_files_reflecting_database_state(): void
    {
        File::factory()->create(['original_name' => 'alpha.pdf']);
        File::factory()->create(['original_name' => 'beta.docx']);

        $response = $this->get('/files');

        $response->assertOk();
        $response->assertSee('alpha.pdf');
        $response->assertSee('beta.docx');
    }

    public function test_index_falls_back_to_defaults_on_invalid_query_params_instead_of_failing(): void
    {
        File::factory()->create();

        $response = $this->get('/files?sort_by=not_a_real_column&order=sideways&per_page=99999');

        $response->assertOk();
    }

    public function test_index_sorts_by_the_requested_column_and_direction(): void
    {
        $small = File::factory()->create(['size_bytes' => 100]);
        $large = File::factory()->create(['size_bytes' => 900]);

        $response = $this->get('/files?sort_by=size_bytes&order=asc');

        $response->assertOk();
        $content = $response->getContent();

        $this->assertLessThan(
            strpos($content, $large->original_name),
            strpos($content, $small->original_name)
        );
    }

    public function test_destroy_soft_deletes_the_record_and_removes_the_physical_file(): void
    {
        $file = File::factory()->create();
        Storage::disk('local')->put($file->stored_path, 'content');

        $response = $this->deleteJson("/files/{$file->id}");

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $file->refresh();
        $this->assertTrue($file->trashed());
        $this->assertSame('manual', $file->deletion_reason);
        Storage::disk('local')->assertMissing($file->stored_path);
    }

    public function test_destroy_returns_404_for_an_already_deleted_file(): void
    {
        $file = File::factory()->create();
        $file->delete();

        $response = $this->deleteJson("/files/{$file->id}");

        $response->assertNotFound();
    }
}
