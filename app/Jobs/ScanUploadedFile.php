<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\FileDeletionService;
use App\Services\VirusScanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ScanUploadedFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    protected int $fileId;

    protected string $disk;

    /**
     * Create a new job instance.
     */
    public function __construct(int $fileId, string $disk = 'local')
    {
        $this->fileId = $fileId;
        $this->disk = $disk;
    }

    /**
     * Execute the job.
     */
    public function handle(VirusScanService $scanner, FileDeletionService $fileDeletionService): void
    {
        /** @var File|null $file */
        $file = File::find($this->fileId);

        if (! $file || ! Storage::disk($this->disk)->exists($file->stored_path)) {
            return;
        }

        $result = $scanner->scanStream(Storage::disk($this->disk)->get($file->stored_path));

        if ($result->isFound()) {
            $fileDeletionService->delete($file->id, 'infected', $this->disk);

            return;
        }

        $file->scan_status = $result->isOk() ? 'clean' : 'error';
        $file->scanned_at = now();
        $file->save();
    }

    /**
     * Handle a job failure once retries are exhausted.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ScanUploadedFile failed permanently', [
            'file_id' => $this->fileId,
            'error' => $exception->getMessage(),
        ]);

        File::whereKey($this->fileId)->update([
            'scan_status' => 'error',
            'scanned_at' => now(),
        ]);
    }
}
