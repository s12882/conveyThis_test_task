<?php

namespace Tests\Feature;

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReapExpiredFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_reaps_an_orphaned_expired_file(): void
    {
        $file = File::factory()->expired()->create();
        Storage::disk('local')->put($file->stored_path, 'content');

        $this->artisan('files:reap-expired')
            ->expectsOutputToContain('Reaped 1 expired file(s).')
            ->assertSuccessful();

        $file->refresh();
        $this->assertTrue($file->trashed());
        $this->assertSame('ttl_safety_net', $file->deletion_reason);
        Storage::disk('local')->assertMissing($file->stored_path);
    }

    public function test_it_ignores_files_that_have_not_expired_yet(): void
    {
        $file = File::factory()->create(['expires_at' => now()->addHour()]);

        $this->artisan('files:reap-expired')
            ->expectsOutputToContain('Reaped 0 expired file(s).')
            ->assertSuccessful();

        $this->assertFalse($file->refresh()->trashed());
    }

    public function test_it_ignores_already_deleted_expired_files(): void
    {
        $file = File::factory()->expired()->create();
        $file->delete();

        $this->artisan('files:reap-expired')
            ->expectsOutputToContain('Reaped 0 expired file(s).')
            ->assertSuccessful();
    }
}
