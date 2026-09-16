<?php

namespace Tests\Feature;

use App\Models\File;
use App\Services\AMPQService;
use App\Services\FileDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FileDeletionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_returns_false_gracefully_when_the_file_does_not_exist(): void
    {
        $result = app(FileDeletionService::class)->delete(999999, 'manual', 'local');

        $this->assertFalse($result);
    }

    public function test_deletion_still_succeeds_when_the_notification_publish_fails(): void
    {
        // Simulates RabbitMQ being temporarily unreachable: the file itself
        // should still be deleted, and delete() should still report success.
        $this->app->bind(AMPQService::class, function () {
            return new class
            {
                public function publishFileDeletion(array $payload): void
                {
                    throw new RuntimeException('RabbitMQ temporarily unreachable');
                }
            };
        });

        $file = File::factory()->create();
        Storage::disk('local')->put($file->stored_path, 'content');

        $result = app(FileDeletionService::class)->delete($file->id, 'manual', 'local');

        $this->assertTrue($result);
        $this->assertTrue($file->refresh()->trashed());
        Storage::disk('local')->assertMissing($file->stored_path);
    }
}
