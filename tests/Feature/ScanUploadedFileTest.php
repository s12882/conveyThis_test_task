<?php

namespace Tests\Feature;

use App\Jobs\ScanUploadedFile;
use App\Models\File;
use App\Services\FileDeletionService;
use App\Services\VirusScanService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;
use Xenolope\Quahog\Result;

class ScanUploadedFileTest extends TestCase
{
    use MockeryPHPUnitIntegration, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_marks_a_clean_file_as_clean_and_sets_scanned_at(): void
    {
        $file = File::factory()->create();
        Storage::disk('local')->put($file->stored_path, 'harmless content');

        $scanner = Mockery::mock(VirusScanService::class);
        $scanner->shouldReceive('scanStream')->once()->andReturn(new Result('OK', 'stream', null, null));

        $deletionService = Mockery::mock(FileDeletionService::class);
        $deletionService->shouldNotReceive('delete');

        (new ScanUploadedFile($file->id))->handle($scanner, $deletionService);

        $file->refresh();
        $this->assertSame('clean', $file->scan_status);
        $this->assertNotNull($file->scanned_at);
        $this->assertNull($file->deletion_reason);
    }

    public function test_deletes_and_flags_an_infected_file(): void
    {
        $file = File::factory()->create();
        Storage::disk('local')->put($file->stored_path, 'stand-in for infected content');

        $scanner = Mockery::mock(VirusScanService::class);
        $scanner->shouldReceive('scanStream')->once()->andReturn(new Result('FOUND', 'stream', 'Eicar-Test-Signature', null));

        $deletionService = Mockery::mock(FileDeletionService::class);
        $deletionService->shouldReceive('delete')->once()->with($file->id, 'infected', 'local');

        (new ScanUploadedFile($file->id))->handle($scanner, $deletionService);
    }

    public function test_marks_scan_status_error_when_clamd_reports_a_scan_error(): void
    {
        $file = File::factory()->create();
        Storage::disk('local')->put($file->stored_path, 'content');

        $scanner = Mockery::mock(VirusScanService::class);
        $scanner->shouldReceive('scanStream')->once()->andReturn(new Result('ERROR', 'stream', 'Size limit exceeded', null));

        $deletionService = Mockery::mock(FileDeletionService::class);
        $deletionService->shouldNotReceive('delete');

        (new ScanUploadedFile($file->id))->handle($scanner, $deletionService);

        $file->refresh();
        $this->assertSame('error', $file->scan_status);
    }

    public function test_skips_scanning_when_the_file_record_no_longer_exists(): void
    {
        $scanner = Mockery::mock(VirusScanService::class);
        $scanner->shouldNotReceive('scanStream');

        $deletionService = Mockery::mock(FileDeletionService::class);
        $deletionService->shouldNotReceive('delete');

        (new ScanUploadedFile(999999))->handle($scanner, $deletionService);
    }

    public function test_skips_scanning_when_the_stored_file_is_missing_from_disk(): void
    {
        $file = File::factory()->create();
        // Deliberately not put on the fake disk.

        $scanner = Mockery::mock(VirusScanService::class);
        $scanner->shouldNotReceive('scanStream');

        $deletionService = Mockery::mock(FileDeletionService::class);

        (new ScanUploadedFile($file->id))->handle($scanner, $deletionService);

        $this->assertSame('pending', $file->refresh()->scan_status);
    }

    public function test_failed_marks_scan_status_error_once_retries_are_exhausted(): void
    {
        $file = File::factory()->create();

        (new ScanUploadedFile($file->id))->failed(new Exception('clamd unreachable'));

        $file->refresh();
        $this->assertSame('error', $file->scan_status);
        $this->assertNotNull($file->scanned_at);
    }
}
